<?php
defined('ABSPATH') || exit;

/**
 * WCA_Identifier_Resolver - Resolves a raw identifier (email or phone) to a WP_User.
 *
 * Routing logic:
 *   contains '@'   wp_users.user_email lookup
 *   digits only    wp_usermeta billing_phone lookup (E.164 normalised)
 */
class WCA_Identifier_Resolver
{

    public static function resolve(string $identifier): WP_User|false
    {
        if (empty($identifier)) {
            return false;
        }

        // -- Email lookup --------------------------------------------------
        if (str_contains($identifier, '@')) {
            $email = sanitize_email($identifier);
            if (is_email($email)) {
                $user = get_user_by('email', $email);
                if ($user instanceof WP_User)
                    return $user;
            }
        }

        // -- Username lookup -----------------------------------------------
        $user = get_user_by('login', $identifier);
        if ($user instanceof WP_User) {
            return $user;
        }

        // -- Phone lookup (E.164 normalised) -------------------------------
        $phone = WCA_Sanitizer::normalize_phone($identifier);
        if ($phone) {
            return self::get_user_by_phone($phone);
        }

        return false;
    }

    // --- Get user by billing_phone usermeta -------------------------------

    private static function get_user_by_phone(string $phone): WP_User|false
    {
        global $wpdb;

        // Try exact match first.
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT um.user_id FROM {$wpdb->usermeta} um
             INNER JOIN {$wpdb->users} u ON um.user_id = u.ID
             WHERE um.meta_key = 'billing_phone' AND um.meta_value = %s
             LIMIT 1",
            $phone
        ));

        // Fallback: If not found, try a wildcard match on the last 10 digits.
        if (!$user_id) {
            $digits = preg_replace('/[^\d]/', '', $phone);
            if (strlen($digits) >= 10) {
                $last_10 = substr($digits, -10);
                $user_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT um.user_id FROM {$wpdb->usermeta} um
                     INNER JOIN {$wpdb->users} u ON um.user_id = u.ID
                     WHERE um.meta_key = 'billing_phone' AND um.meta_value LIKE %s
                     LIMIT 1",
                    '%' . $last_10
                ));
            }
        }

        if (!$user_id) {
            return false;
        }

        $user = get_user_by('id', (int) $user_id);
        return $user instanceof WP_User ? $user : false;
    }
}
