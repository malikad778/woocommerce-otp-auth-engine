<?php
defined('ABSPATH') || exit;

/**
 * WCA_Account_Migrator - One-time migration: flags pre-existing unverified accounts.
 *
 * IRREVERSIBLE. Admin-triggered only via the network admin Migration Panel.
 * Does NOT delete users. Does NOT send OTPs. Sets wca_needs_reverification = 1
 * on accounts where account_status is not 'approved'.
 */
class WCA_Account_Migrator
{

    /**
     * Flag all unverified accounts for re-verification on next login.
     * Runs as a WP-Cron background task.
     */
    public static function flag_unverified_accounts(): void
    {
        global $wpdb;

        // Find all users where account_status is NOT 'approved'
        // (covers unverified, pending, or missing meta key entirely).
        $results = $wpdb->get_results("
            SELECT u.ID
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->usermeta} um
                ON u.ID = um.user_id AND um.meta_key = 'account_status'
            WHERE um.meta_value IS NULL
               OR um.meta_value != 'approved'
        ");

        $flagged = 0;

        foreach ($results as $row) {
            $user_id = (int) $row->ID;

            // Skip super admins.
            if (is_super_admin($user_id)) {
                continue;
            }

            update_user_meta($user_id, 'wca_needs_reverification', 1);
            $flagged++;

            WCA_Logger::log('MIGRATION_FLAGGED', [
                'user_id' => $user_id,
            ]);
        }

        // Mark migration as complete.
        update_site_option('wca_migration_run', true);
        update_site_option('wca_migration_flagged_count', $flagged);
        update_site_option('wca_migration_run_at', current_time('c'));

        WCA_Logger::log('MIGRATION_COMPLETE', [
            'flagged_count' => $flagged,
        ]);
    }

    /**
     * Schedule the migration to run via WP-Cron (avoids timeout on large user bases).
     */
    public static function schedule(): void
    {
        if (!wp_next_scheduled('wca_run_migration')) {
            wp_schedule_single_event(time() + 5, 'wca_run_migration');
        }
    }

    // --- Repair Stale Flags ---------------------------------------------------

    /**
     * Bulk-repair all users on all network sites who have wca_needs_reverification = 1
     * but are actually fully verified (billing_email_verified = 1 AND
     * billing_phone_verified = 1). Clears the stale flag and sets
     * account_status = 'approved' so everything is in sync.
     *
     * Safe to run multiple times (idempotent).
     * Runs via WP-Cron, triggered from the Migration admin panel.
     */
    public static function repair_stale_flags(): void
    {
        global $wpdb;

        $repaired_total = 0;
        $scanned_total = 0;

        // On multisite, iterate every blog so we hit the correct usermeta table.
        $sites = is_multisite() ? get_sites(['number' => 9999]) : [null];

        foreach ($sites as $site) {
            if (is_multisite() && $site) {
                switch_to_blog($site->blog_id);
            }

            // Find users flagged for reverification who are actually fully verified.
            // Both billing_email_verified AND billing_phone_verified must be '1'.
            $results = $wpdb->get_results("
                SELECT rv.user_id
                FROM {$wpdb->usermeta} rv
                INNER JOIN {$wpdb->usermeta} ev
                    ON rv.user_id = ev.user_id
                    AND ev.meta_key = 'billing_email_verified'
                    AND ev.meta_value = '1'
                INNER JOIN {$wpdb->usermeta} pv
                    ON rv.user_id = pv.user_id
                    AND pv.meta_key = 'billing_phone_verified'
                    AND pv.meta_value = '1'
                WHERE rv.meta_key  = 'wca_needs_reverification'
                  AND rv.meta_value = '1'
            ");

            $scanned_total += count($results);

            foreach ($results as $row) {
                $user_id = (int) $row->user_id;

                if (is_super_admin($user_id)) {
                    continue;
                }

                delete_user_meta($user_id, 'wca_needs_reverification');
                update_user_meta($user_id, 'account_status', 'approved');
                $repaired_total++;

                WCA_Logger::log('STALE_FLAG_REPAIRED', [
                    'user_id' => $user_id,
                    'blog_id' => is_multisite() && $site ? (int) $site->blog_id : get_current_blog_id(),
                ]);
            }

            if (is_multisite() && $site) {
                restore_current_blog();
            }
        }

        // Store results so the admin panel can display them.
        update_site_option('wca_repair_run', true);
        update_site_option('wca_repair_run_at', current_time('c'));
        update_site_option('wca_repair_scanned_count', $scanned_total);
        update_site_option('wca_repair_fixed_count', $repaired_total);

        WCA_Logger::log('STALE_FLAG_REPAIR_COMPLETE', [
            'scanned' => $scanned_total,
            'repaired' => $repaired_total,
        ]);
    }

    /**
     * Schedule the stale-flag repair to run via WP-Cron.
     * Can be called multiple times safely - won't double-schedule.
     */
    public static function schedule_repair(): void
    {
        if (!wp_next_scheduled('wca_repair_stale_flags')) {
            wp_schedule_single_event(time() + 5, 'wca_repair_stale_flags');
        }
    }
}

// Register cron hooks.
add_action('wca_run_migration', ['WCA_Account_Migrator', 'flag_unverified_accounts']);
add_action('wca_repair_stale_flags', ['WCA_Account_Migrator', 'repair_stale_flags']);
