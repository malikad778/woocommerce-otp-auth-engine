<?php
defined( 'ABSPATH' ) || exit;

/**
 * WCA_Deactivator - Cleanup on plugin deactivation.
 *
 * Note: Does NOT delete user data or settings - only clears ephemeral state
 * (transients, cron). Permanent data removal happens in uninstall.php.
 */
class WCA_Deactivator {

    public static function deactivate(): void {
        self::clear_cron();
        self::clear_transients();
    }

    private static function clear_cron(): void {
        $timestamp = wp_next_scheduled( 'wca_transient_sweep' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'wca_transient_sweep' );
        }
        
        $cleanup_timestamp = wp_next_scheduled( 'wca_daily_cleanup' );
        if ( $cleanup_timestamp ) {
            wp_unschedule_event( $cleanup_timestamp, 'wca_daily_cleanup' );
        }
    }

    private static function clear_transients(): void {
        global $wpdb;

        $prefixes = [ 'wca_pending_', 'wca_login_otp_', 'wca_profile_upd_', 'wca_rl_' ];
        foreach ( $prefixes as $prefix ) {
            $like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

            $like_t = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_t ) );
        }
    }
}
