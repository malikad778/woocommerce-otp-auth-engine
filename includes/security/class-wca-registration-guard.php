<?php
defined('ABSPATH') || exit;

/**
 * WCA_Registration_Guard - Fail-safe cleanup for accounts created outside
 * WCA_Registration_Completer::create_user() (e.g. a native WordPress,
 * WooCommerce, or multisite signup form that slipped past the front-line
 * blocks in wca-auth-engine.php via some bypass this plugin doesn't yet
 * know about). Any such account never receives this plugin's own
 * verification meta, so it's deleted automatically once the request that
 * created it finishes - never touches accounts an admin creates by hand.
 *
 * Deliberately does NOT check meta immediately on user_register: even a
 * genuine WCA_Registration_Completer::create_user() signup hasn't set its
 * verification meta yet at that exact instant (it sets it a few lines
 * later, in the same request). Checking at 'shutdown' lets that finish
 * first, for every code path - ours or a bypass - since both complete
 * within a single request.
 */
class WCA_Registration_Guard
{

    /** @var array<int,true> user_id => pending verification check */
    private static array $pending_checks = [];

    public static function init(): void
    {
        add_action('user_register', [self::class, 'track_new_user'], 20, 1);
        add_action('shutdown', [self::class, 'verify_pending_users']);
    }

    // --- Capture context at the moment of creation -------------------------

    public static function track_new_user(int $user_id): void
    {
        if (self::is_privileged_context()) {
            return;
        }

        self::$pending_checks[$user_id] = true;
    }

    /**
     * True for anything that isn't a bot hitting a public HTTP endpoint:
     * an admin creating a user by hand (wp-admin "Add New User", or the
     * REST API/WP-CLI used with real admin credentials), or a WP-CLI script
     * with no HTTP request involved at all.
     */
    private static function is_privileged_context(): bool
    {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        return current_user_can('create_users');
    }

    // --- Verify at the end of the request ----------------------------------

    public static function verify_pending_users(): void
    {
        $user_ids = array_keys(self::$pending_checks);
        self::$pending_checks = [];

        foreach ($user_ids as $user_id) {
            self::verify_user($user_id);
        }
    }

    private static function verify_user(int $user_id): void
    {
        $user = get_userdata($user_id);
        if (!$user) {
            return; // Already gone somehow - nothing to do.
        }

        if (self::has_completed_verification($user_id)) {
            return;
        }

        $masked_email = WCA_Logger::mask_email($user->user_email);

        self::delete_user($user_id);

        WCA_Logger::log('BYPASS_ACCOUNT_DELETED', [
            'deleted_user_id' => $user_id,
            'masked_email' => $masked_email,
        ]);
    }

    /**
     * The exact marker WCA_Registration_Completer::create_user() sets
     * immediately after a genuine OTP-verified signup. Its absence means
     * the account was created through some other, unverified path.
     */
    private static function has_completed_verification(int $user_id): bool
    {
        return get_user_meta($user_id, 'account_status', true) === 'approved'
            && (bool) get_user_meta($user_id, 'billing_phone_verified', true)
            && (bool) get_user_meta($user_id, 'billing_email_verified', true);
    }

    /**
     * On multisite, wp_delete_user() only removes the user from the
     * current site - the account itself survives network-wide. A bot
     * account needs the full network deletion, wpmu_delete_user().
     */
    private static function delete_user(int $user_id): void
    {
        if (is_multisite()) {
            if (!function_exists('wpmu_delete_user')) {
                require_once ABSPATH . 'wp-admin/includes/ms.php';
            }
            wpmu_delete_user($user_id);
        } else {
            if (!function_exists('wp_delete_user')) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
            }
            wp_delete_user($user_id);
        }
    }
}
