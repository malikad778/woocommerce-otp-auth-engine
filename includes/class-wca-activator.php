<?php
defined('ABSPATH') || exit;

/**
 * WCA_Activator - Handles network-wide plugin activation tasks.
 */
class WCA_Activator
{

    public static function activate(): void
    {
        self::create_log_directory();
        self::set_default_settings();
        self::schedule_cron();
    }

    // --- Log directory ----------------------------------------------------

    private static function create_log_directory(): void
    {
        $log_dir = dirname(WCA_LOG_PATH);

        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        // Protect the directory from direct web access.
        $htaccess = $log_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Order Allow,Deny\nDeny from all\n");
        }

        // Block directory listing.
        $index = $log_dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php // Silence is golden.\n");
        }

        // Ensure the log file itself exists (empty).
        if (!file_exists(WCA_LOG_PATH)) {
            file_put_contents(WCA_LOG_PATH, '');
        }
    }

    // --- Default settings in wp_sitemeta ---------------------------------

    private static function set_default_settings(): void
    {
        $defaults = [
            'wca_otp_ttl' => 600,
            'wca_sms_rate_limit' => 10,
            'wca_sms_rate_window' => 900,
            'wca_recaptcha_threshold' => 0.5,
            'wca_migration_run' => false,

            // Anti-SMS-pumping defaults. Deliberately conservative: a site
            // that is activated before its settings are filled in must fail
            // closed, not spend the account's SMS balance.
            'wca_sms_enabled' => 1,
            'wca_sms_allowed_countries' => '44,35,39,97,41,33,32',
            'wca_sms_phone_limit' => 3,
            'wca_sms_phone_window' => 3600,
            'wca_sms_global_limit' => 100,
            'wca_sms_global_window' => 3600,
            'wca_recaptcha_fail_open' => 0,
            'wca_trusted_proxy_header' => '',
            'wca_email_recipient_limit' => 5,
            'wca_email_recipient_window' => 3600,
            'wca_email_global_limit' => 200,
            'wca_email_global_window' => 3600,
        ];

        foreach ($defaults as $key => $value) {
            // add_site_option only sets the value if the key doesn't already exist.
            add_site_option($key, $value);
        }
    }

    // --- WP-Cron event ---------------------------------------------------

    private static function schedule_cron(): void
    {
        if (!wp_next_scheduled('wca_transient_sweep')) {
            wp_schedule_event(time(), 'hourly', 'wca_transient_sweep');
        }
        if (!wp_next_scheduled('wca_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wca_daily_cleanup');
        }
        update_site_option('wca_cron_registered', true);
    }
}
