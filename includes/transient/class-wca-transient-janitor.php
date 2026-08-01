<?php
defined( 'ABSPATH' ) || exit;

/**
 * WCA_Transient_Janitor - Hourly WP-Cron task that sweeps orphaned WCA transients
 * and logs REGISTRATION_DROPPED for any abandoned sessions.
 */
class WCA_Transient_Janitor {

    public static function sweep(): void {
        global $wpdb;

        $prefixes = [
            'wca_pending_'    => 'REGISTRATION_DROPPED',
            'wca_login_otp_'  => 'LOGIN_SESSION_DROPPED',
            'wca_profile_upd_'=> 'PROFILE_SESSION_DROPPED',
        ];

        foreach ( $prefixes as $prefix => $event ) {
            // Find any WCA transient keys that are still in wp_options
            // but whose _timeout_ counterpart has already expired (or is missing).
            $transient_like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
            $timeout_like   = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';

            // Get all timeout entries.
            $timeout_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options}
                 WHERE option_name LIKE %s",
                $timeout_like
            ) );

            foreach ( $timeout_rows as $row ) {
                $expiry = (int) $row->option_value;

                // If expired (WordPress should have deleted it already, but may not have).
                if ( $expiry < time() ) {
                    // Derive the data key from the timeout key.
                    $data_key = str_replace( '_transient_timeout_', '_transient_', $row->option_name );
                    $transient_name = str_replace( '_transient_', '', $data_key );

                    // Log the drop event.
                    WCA_Logger::log( $event, [
                        'transient_key' => $prefix . '...',
                        'expired_at'    => date( 'c', $expiry ),
                    ] );

                    // Force-delete orphan.
                    delete_transient( $transient_name );
                }
            }
        }
    }
}
