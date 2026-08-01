<?php
defined('ABSPATH') || exit;

/**
 * WCA_Endpoint_Login - Handles /login/* REST routes.
 */
class WCA_Endpoint_Login
{

    // --- POST /login/check-identifier -------------------------------------

    public static function check_identifier(WP_REST_Request $request): WP_REST_Response
    {
        $raw = $request->get_param('identifier');
        $identifier = WCA_Sanitizer::sanitize_identifier($raw);

        if (empty($identifier)) {
            return WCA_API_Router::error('invalid_identifier', 'Please enter a valid email address, phone number, or username.', 422);
        }

        $user = WCA_Identifier_Resolver::resolve($identifier);

        if (!$user instanceof WP_User) {
            // User not found - redirect to register with pre-fill.
            if (str_contains($identifier, '@')) {
                $field = 'email';
            } elseif (preg_match('/^[+0-9\s-]+$/', $identifier)) {
                $field = 'phone';
            } else {
                $field = 'none';
            }

            return WCA_API_Router::success([
                'exists' => false,
                'prefill' => ['field' => $field, 'value' => $identifier],
            ]);
        }

        return WCA_API_Router::success([
            'exists' => true,
            'user_id' => $user->ID,
            'needs_reverification' => (bool) get_user_meta($user->ID, 'wca_needs_reverification', true),
        ]);
    }

    // --- POST /login/send-otp ---------------------------------------------

    public static function send_otp(WP_REST_Request $request): WP_REST_Response
    {
        $identifier = WCA_Sanitizer::sanitize_identifier($request->get_param('identifier'));
        $channel = $request->get_param('channel') ?: 'sms';

        // reCAPTCHA gate.
        $rc = WCA_Recaptcha::verify($request->get_param('recaptcha_token'));
        if (is_wp_error($rc)) {
            WCA_Logger::log('RECAPTCHA_FAILED', ['step' => 'login_send_otp']);
            return WCA_API_Router::error('recaptcha_failed', 'Security check failed.', 403);
        }

        // IP rate limit. Applies to both channels: an unmetered email OTP
        // endpoint is an email-bombing tool aimed at whichever customer the
        // attacker names, and it damages the sending domain's reputation
        // just as effectively as the SMS flood damaged the bill.
        $ip_check = WCA_Rate_Limiter::check(WCA_Rate_Limiter::get_client_ip());
        if (is_wp_error($ip_check)) {
            return WCA_API_Router::error('rate_limited', 'Too many requests. Please wait 15 minutes before requesting a new code.', 429);
        }

        $user = WCA_Identifier_Resolver::resolve($identifier);
        if (!$user instanceof WP_User) {
            return WCA_API_Router::error('user_not_found', 'No account found with that identifier.', 404);
        }

        $result = WCA_Auth_Engine::dispatch_login_otp($user, $channel);
        if (is_wp_error($result)) {
            return WCA_API_Router::error($result->get_error_code(), $result->get_error_message(), 500);
        }

        return WCA_API_Router::success([
            'otp_sent' => true,
            'session_token' => $result['session_token'],
            'expires_in' => WCA_Constants::otp_ttl(),
            'channel' => $channel,
        ]);
    }

    // --- POST /login/authenticate -----------------------------------------

    public static function authenticate(WP_REST_Request $request): WP_REST_Response
    {
        $identifier = WCA_Sanitizer::sanitize_identifier($request->get_param('identifier'));
        $password = $request->get_param('password');
        $otp_code = $request->get_param('otp_code');
        $session_token = $request->get_param('session_token');

        if (empty($identifier)) {
            return WCA_API_Router::error('invalid_identifier', 'Identifier is required.', 422);
        }

        $user = WCA_Identifier_Resolver::resolve($identifier);
        if (!$user instanceof WP_User) {
            return WCA_API_Router::error('user_not_found', 'No account found with that identifier.', 404);
        }

        // -- Password path --
        if (!empty($password)) {
            $result = WCA_Auth_Engine::authenticate_password($user, $password);
            if (is_wp_error($result)) {
                return WCA_API_Router::error($result->get_error_code(), $result->get_error_message(), 401);
            }
            return self::issue_auth_response($user, $request);
        }

        // -- OTP path --
        if (!empty($otp_code) && !empty($session_token)) {
            $result = WCA_Auth_Engine::authenticate_otp($user, $otp_code, $session_token);
            if (is_wp_error($result)) {
                return WCA_API_Router::error($result->get_error_code(), $result->get_error_message(), 401);
            }
            return self::issue_auth_response($user, $request);
        }

        return WCA_API_Router::error('missing_credentials', 'Provide either a password or OTP code + session token.', 422);
    }

    // --- Internal: issue auth cookie + return success response -----------

    private static function issue_auth_response(WP_User $user, ?WP_REST_Request $request = null): WP_REST_Response
    {
        // 1. Account lock check (hard ban - blocks all login methods).
        if (get_user_meta($user->ID, WCA_Admin_User_Tools::LOCK_META_KEY, true)) {
            WCA_Logger::log('AUTH_BLOCKED_LOCKED', [
                'user_id' => $user->ID,
                'masked_email' => WCA_Logger::mask_email($user->user_email),
            ]);
            return WCA_API_Router::error(
                'account_locked',
                WCA_Admin_User_Tools::get_suspension_message($user->ID),
                403
            );
        }

        // Catch users with no status (e.g. newly created by admin) before they login,
        // flag them, but ALLOW them to log in so they can access the reverification modal.
        if (!get_user_meta($user->ID, 'account_status', true) && !user_can($user->ID, 'manage_options')) {
            update_user_meta($user->ID, 'wca_needs_reverification', 1);
        }

        WCA_Session_Manager::issue_cookie($user);

        WCA_Logger::log('AUTH_SUCCESS', [
            'user_id' => $user->ID,
            'blog_id' => get_current_blog_id(),
            'masked_email' => WCA_Logger::mask_email($user->user_email),
        ]);

        // Fix #8: Respect server-supplied redirect_to if it is a safe local URL.
        $redirect_to = $request ? sanitize_text_field($request->get_param('redirect_to') ?? '') : '';
        $safe_redirect = ($redirect_to && str_starts_with($redirect_to, home_url()))
            ? $redirect_to
            : wc_get_page_permalink('myaccount');

        return WCA_API_Router::success([
            'redirect' => $safe_redirect,
            'message' => 'Login successful.',
            'login_notify' => wp_kses_post(WCA_Login_Notify::get_message($user->ID)),
        ]);
    }
}
