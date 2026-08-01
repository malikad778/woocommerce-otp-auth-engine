<?php
defined('ABSPATH') || exit;

/**
 * WCA_Endpoint_OTP - Handles /otp/resend REST route.
 *
 * Session lookup strategy:
 *   1. Try wca_pending_{session_token}          registration context
 *   2. Try wca_login_otp_{session_token}        login OTP context
 * This avoids requiring the client to send a context flag.
 */
class WCA_Endpoint_OTP
{

    // --- POST /otp/resend -------------------------------------------------

    public static function resend(WP_REST_Request $request): WP_REST_Response
    {
        $session_token = $request->get_param('session_token');
        $channel = $request->get_param('channel') ?: 'sms';
        $ip = WCA_Rate_Limiter::get_client_ip();

        // reCAPTCHA gate.
        $rc = WCA_Recaptcha::verify($request->get_param('recaptcha_token'));
        if (is_wp_error($rc)) {
            WCA_Logger::log('RECAPTCHA_FAILED', ['step' => 'otp_resend', 'ip_hash' => md5($ip . AUTH_SALT)]);
            return WCA_API_Router::error('recaptcha_failed', 'Security check failed.', 403);
        }

        // Rate-limit resends on BOTH channels. Previously email resends were
        // unmetered, so one valid session token bought unlimited outbound
        // email - a cheaper flood than the SMS one, and invisible until the
        // sending domain gets throttled.
        $rl = WCA_Rate_Limiter::check($ip);
        if (is_wp_error($rl)) {
            WCA_Logger::log('OTP_RATE_LIMITED', [
                'ip_hash' => md5($ip . AUTH_SALT),
                'channel' => $channel,
                'step' => 'resend',
            ]);
            return WCA_API_Router::error('rate_limited', 'Too many requests. Please wait before requesting a new code.', 429);
        }

        $store = new WCA_Transient_Store();

        // -- Strategy 1: Registration session ----------------------------
        $reg_key = WCA_Constants::transient_registration($session_token);  // wca_pending_{token}
        $payload = $store->get($reg_key);

        if ($payload) {
            $result = WCA_OTP_Dispatcher::resend_registration_otp($payload, $session_token, $channel);

            if (is_wp_error($result)) {
                return WCA_API_Router::error($result->get_error_code(), $result->get_error_message(), 500);
            }

            return WCA_API_Router::success([
                'resent' => true,
                'context' => 'registration',
                'channel' => $channel,
                'expires_in' => WCA_Constants::otp_ttl(),
                'message' => 'New code sent.',
            ]);
        }

        // -- Strategy 2: Login OTP session --------------------------------
        $login_key = 'wca_login_otp_' . $session_token;  // starts with wca_  passes through resolve_key
        $payload = $store->get($login_key);

        if ($payload) {
            $user_id = (int) ($payload['user_id'] ?? 0);
            $user = $user_id ? get_user_by('id', $user_id) : false;

            if (!$user instanceof WP_User) {
                return WCA_API_Router::error('user_not_found', 'Session user is invalid.', 404);
            }

            $result = WCA_OTP_Dispatcher::resend_login_otp($user, $payload, $session_token, $channel);

            if (is_wp_error($result)) {
                return WCA_API_Router::error($result->get_error_code(), $result->get_error_message(), 500);
            }

            return WCA_API_Router::success([
                'resent' => true,
                'context' => 'login',
                'channel' => $channel,
                'expires_in' => WCA_Constants::otp_ttl(),
                'message' => 'New code sent.',
            ]);
        }

        // -- Neither context found: session expired -----------------------
        return WCA_API_Router::error('session_expired', 'Your session has expired. Please start again.', 410);
    }

    // --- POST /otp/status -------------------------------------------------

    /**
     * Checks if a session is still active. Used by the frontend to detect
     * if a session was completed in another browser/tab.
     */
    public static function status(WP_REST_Request $request): WP_REST_Response
    {
        $session_token = $request->get_param('session_token');
        if (!$session_token) {
            return WCA_API_Router::error('missing_token', 'Session token required', 400);
        }

        $store = new WCA_Transient_Store();

        // Check registration session
        $reg_key = WCA_Constants::transient_registration($session_token);
        $payload = $store->get($reg_key);
        if ($payload) {
            return WCA_API_Router::success([
                'active' => true,
                'email_verified' => !empty($payload['email_verified']),
                'sms_verified' => !empty($payload['sms_verified']),
            ]);
        }

        // Check login session
        $login_key = 'wca_login_otp_' . $session_token;
        if ($store->get($login_key)) {
            return WCA_API_Router::success(['active' => true]);
        }

        return WCA_API_Router::error('session_expired', 'Session no longer active.', 410);
    }
}
