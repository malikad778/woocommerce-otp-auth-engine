<?php
defined('ABSPATH') || exit;

/**
 * WCA_Registration_Completer - Creates the WP user after both verifications pass.
 * This is the ONLY place wp_create_user() is ever called in the plugin.
 */
class WCA_Registration_Completer
{

    /**
     * @param array  $payload       Decrypted transient payload.
     * @param string $transient_key The full transient key to delete on success.
     */
    public static function create_user(array $payload, string $transient_key): array|WP_Error
    {
        // Final duplicate guard - race condition protection.
        if (email_exists($payload['email'])) {
            (new WCA_Transient_Store())->delete($transient_key);
            return new WP_Error('email_exists', 'An account with that email address was already registered.');
        }

        // Generate a unique username from email (WooCommerce convention).
        $username = self::generate_username($payload['email']);

        // wp_create_user expects a raw password, but we've already hashed it.
        // We create the user with a random password then update the hash directly.
        $temp_password = wp_generate_password(24, true, true);
        $user_id = wp_create_user($username, $temp_password, $payload['email']);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        // Update display fields first (this will use the temp password).
        wp_update_user([
            'ID' => $user_id,
            'first_name' => $payload['first_name'],
            'last_name' => $payload['last_name'],
            'display_name' => $payload['first_name'] . ' ' . $payload['last_name'],
        ]);

        // Inject the proper password hash directly AFTER wp_update_user, 
        // to prevent wp_update_user from overwriting it with the cached temp password.
        global $wpdb;
        $wpdb->update(
            $wpdb->users,
            ['user_pass' => $payload['password_hash']],
            ['ID' => $user_id],
            ['%s'],
            ['%d']
        );
        clean_user_cache($user_id);

        // Usermeta - account status + billing phone.
        update_user_meta($user_id, 'account_status', 'approved');
        update_user_meta($user_id, 'billing_phone', $payload['phone']);
        update_user_meta($user_id, 'billing_phone_verified', 1);
        update_user_meta($user_id, 'billing_first_name', $payload['first_name']);
        update_user_meta($user_id, 'billing_last_name', $payload['last_name']);
        update_user_meta($user_id, 'billing_email', $payload['email']);
        update_user_meta($user_id, 'billing_email_verified', 1);



        // Assign subscriber role on the correct blog (multisite-aware).
        $blog_id = $payload['blog_id'] ?? get_current_blog_id();
        $user = new WP_User($user_id);

        if (is_multisite()) {
            add_user_to_blog($blog_id, $user_id, 'subscriber');
        } else {
            $user->set_role('subscriber');
        }

        // Clean up transient.
        (new WCA_Transient_Store())->delete($transient_key);

        WCA_Logger::log('USER_CREATED', [
            'user_id' => $user_id,
            'blog_id' => $blog_id,
            'masked_email' => WCA_Logger::mask_email($payload['email']),
            'masked_phone' => WCA_Logger::mask_phone($payload['phone']),
        ]);

        // Issue auth cookie so the user is immediately logged in.
        $wp_user = get_user_by('id', $user_id);
        WCA_Session_Manager::issue_cookie($wp_user);

        return ['user_id' => $user_id];
    }

    // --- Username generator -----------------------------------------------

    private static function generate_username(string $email): string
    {
        $base = sanitize_user(strstr($email, '@', true), true);
        $base = empty($base) ? 'user' : $base;
        $username = $base;
        $counter = 1;

        while (username_exists($username)) {
            $username = $base . $counter;
            $counter++;
        }

        return $username;
    }
}
