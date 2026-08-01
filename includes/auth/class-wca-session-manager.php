<?php
defined( 'ABSPATH' ) || exit;

/**
 * WCA_Session_Manager - Issues WordPress authentication cookies after successful auth.
 */
class WCA_Session_Manager {

    /**
     * Issue auth cookie for the given user.
     * Uses wp_set_auth_cookie() which handles both single-site and multisite.
     *
     * @param WP_User $user         The authenticated user.
     * @param bool    $remember     Whether to set a long-lived cookie.
     */
    public static function issue_cookie( WP_User $user, bool $remember = false ): void {
        // Destroy any existing session to prevent session fixation.
        wp_destroy_current_session();

        // Clean up any stray nonces / old session data.
        wp_clear_auth_cookie();

        // Set the authentication cookie.
        wp_set_auth_cookie( $user->ID, $remember, is_ssl() );

        // Trigger the standard WP login action so hooks (e.g. last_login plugins) fire.
        do_action( 'wp_login', $user->user_login, $user );

        // Store the login timestamp.
        update_user_meta( $user->ID, 'last_login', current_time( 'timestamp' ) );
    }

    /**
     * Destroy the current user session (logout).
     */
    public static function destroy( int $user_id = 0 ): void {
        if ( $user_id ) {
            // Destroy all sessions for a specific user.
            $sessions = WP_Session_Tokens::get_instance( $user_id );
            $sessions->destroy_all();
        } else {
            wp_logout();
        }
    }
}
