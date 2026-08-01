<?php
/**
 * WCA Auth Engine - Network-safe uninstall routine.
 *
 * Fired automatically by WordPress when the plugin is deleted via the admin UI.
 * Clears: all wca_pending_* / wca_login_otp_* / wca_profile_upd_* / wca_rl_* transients,
 * all wp_sitemeta settings, and the log file.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// -------------------------------------------------------------
// 1. Delete all WCA transients from wp_options (single-site) and
//    wp_sitemeta (network).
// -------------------------------------------------------------
$transient_prefixes = ['wca_pending_', 'wca_login_otp_', 'wca_profile_upd_', 'wca_rl_'];

foreach ($transient_prefixes as $prefix) {
    $like = $wpdb->esc_like('_transient_' . $prefix) . '%';
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like
        )
    );
    // Also delete timeout entries.
    $like_timeout = $wpdb->esc_like('_transient_timeout_' . $prefix) . '%';
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like_timeout
        )
    );
}

// -------------------------------------------------------------
// 2. Delete wp_sitemeta settings.
// -------------------------------------------------------------
$site_meta_keys = [
    // Legacy Brevo integration (removed - kept here so old installs are cleaned up).
    'wca_brevo_api_key',
    'wca_brevo_template_registration',
    'wca_brevo_template_login',
    'wca_brevo_template_forgot_password',
    'wca_brevo_template_profile_update',
    // Native email engine.
    'wca_email_body_registration',
    'wca_email_body_login',
    'wca_email_body_forgot_password',
    'wca_email_body_profile_update',
    'wca_global_announcement',
    'wca_plugin_version',
    'wca_textmagic_username',
    'wca_textmagic_api_key',
    'wca_recaptcha_site_key',
    'wca_recaptcha_secret_key',
    'wca_otp_ttl',
    'wca_sms_rate_limit',
    'wca_sms_rate_window',
    'wca_recaptcha_threshold',
    'wca_migration_run',
    'wca_cron_registered',
];

foreach ($site_meta_keys as $key) {
    delete_site_option($key);
}

delete_transient('wca_brevo_active_sender_cache');

// -------------------------------------------------------------
// 3. Clear the WP-Cron event.
// -------------------------------------------------------------
$timestamp = wp_next_scheduled('wca_transient_sweep');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'wca_transient_sweep');
}

// -------------------------------------------------------------
// 4. Truncate the log file (do not delete the directory -
//    other processes may write to it).
// -------------------------------------------------------------
$log_path = WP_CONTENT_DIR . '/uploads/logs/auth_engine.log';
if (file_exists($log_path)) {
    file_put_contents($log_path, '');
}

// -------------------------------------------------------------
// 5. Remove usermeta flags set by WCA (across all network users).
//    Note: We do NOT touch account_status or billing_phone - those
//    are operational data the site may still need.
// -------------------------------------------------------------
$wpdb->query(
    "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('wca_needs_reverification', 'wca_login_notify_message', 'wca_login_notify')"
);
