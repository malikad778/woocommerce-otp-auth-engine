<?php
defined('ABSPATH') || exit;

/**
 * WCA_Endpoint_Password - Handles /password/* REST routes.
 *
 * Audit fixes applied:
 *   #3  - OTP verification now uses WCA_OTP_Validator (enforces TTL, not just bcrypt).
 *   #4  - All transient reads/writes use WCA_Transient_Store (AES-256 encrypted).
 *   #5  - Locked accounts are blocked from initiating AND completing password reset.
 *   #10 - WCA_Rate_Limiter::check() called with one argument only.
 */
class WCA_Endpoint_Password
{

    // --- POST /password/forgot -------------------------------------------

    public static function initiate_reset(WP_REST_Request $request): WP_REST_Response
    {
        $identifier = WCA_Sanitizer::sanitize_identifier($request->get_param('identifier'));

        $rc = WCA_Recaptcha::verify($request->get_param('recaptcha_token'));
        if (is_wp_error($rc)) {
            return WCA_API_Router::error('recaptcha_failed', 'Security check failed.', 403);
        }

        // Fix #10: check() only accepts one argument; extra args were silently ignored.
        $ip_check = WCA_Rate_Limiter::check(WCA_Rate_Limiter::get_client_ip());
        if (is_wp_error($ip_check)) {
            return WCA_API_Router::error('rate_limited', 'Too many requests. Please wait 15 minutes.', 429);
        }

        if (empty($identifier)) {
            return WCA_API_Router::error('invalid_identifier', 'Please enter a valid email address or phone number.', 422);
        }

        $user = WCA_Identifier_Resolver::resolve($identifier);

        if (!$user instanceof WP_User) {
            return WCA_API_Router::error('user_not_found', 'No account was found with that email or phone number.', 404);
        }

        if (get_user_meta($user->ID, WCA_Admin_User_Tools::LOCK_META_KEY, true)) {
            return WCA_API_Router::error(
                'account_locked',
                WCA_Admin_User_Tools::get_suspension_message($user->ID),
                403
            );
        }

        // Determine channel
        $channel = str_contains($identifier, '@') ? 'email' : 'sms';
        if ($channel === 'sms') {
            $phone = get_user_meta($user->ID, 'billing_phone', true);
            if (!$phone) {
                return WCA_API_Router::error('no_phone', 'No phone number attached to this account.', 422);
            }
        }

        // Generate session & OTP
        $session_token = bin2hex(random_bytes(32));
        $transient_key = 'wca_pw_reset_' . $session_token;

        $otp_data = WCA_OTP_Generator::generate();

        $session_data = [
            'user_id' => $user->ID,
            'otp_hash' => $otp_data['hash'],
            'expires' => $otp_data['expiry'],
            'attempts' => 0,
            'otp_verified' => false,
            'channel' => $channel,
        ];

        // Fix #4: Use AES-256-encrypted WCA_Transient_Store.
        $store = new WCA_Transient_Store();
        $store->set($transient_key, $session_data, WCA_Constants::otp_ttl());

        // Dispatch
        if ($channel === 'email') {
            $email_sent = WCA_OTP_Dispatcher::send_password_forgot_email(
                $user->user_email,
                $otp_data['code'],
                $user->display_name
            );
            if (is_wp_error($email_sent)) {
                return WCA_API_Router::error(
                    'email_failed',
                    'Failed to send reset code: ' . $email_sent->get_error_message(),
                    500
                );
            }
        } else {
            WCA_TextMagic_Client::send_sms($phone, $otp_data['code']);
        }

        WCA_Logger::log('PASSWORD_RESET_REQUESTED', [
            'user_id' => $user->ID,
            'channel' => $channel,
        ]);

        return WCA_API_Router::success([
            'message' => 'A verification code has been sent to your ' . ($channel === 'email' ? 'email address' : 'phone') . '.',
            'session_token' => $session_token,
            'channel' => $channel,
            'expires_in' => WCA_Constants::otp_ttl(),
        ]);
    }

    // --- POST /password/verify-otp ---------------------------------------

    public static function verify_reset_otp(WP_REST_Request $request): WP_REST_Response
    {
        $session_token = $request->get_param('session_token');
        $otp_code = $request->get_param('otp_code');

        $transient_key = 'wca_pw_reset_' . $session_token;

        // Fix #4: Retrieve from encrypted store.
        $store = new WCA_Transient_Store();
        $session_data = $store->get($transient_key);

        if (!$session_data || time() > $session_data['expires']) {
            return WCA_API_Router::error('session_expired', 'Session expired. Please start over.', 410);
        }

        if ($session_data['attempts'] >= 5) {
            $store->delete($transient_key);
            return WCA_API_Router::error('too_many_attempts', 'Too many failed attempts. Please start over.', 403);
        }

        // Fix #3: Use WCA_OTP_Validator which also enforces TTL - not just bcrypt match.
        $validation = WCA_OTP_Validator::validate(
            $otp_code,
            $session_data['otp_hash'],
            $session_data['expires']
        );

        if (is_wp_error($validation)) {
            $session_data['attempts']++;
            $store->set($transient_key, $session_data, WCA_Constants::otp_ttl());
            return WCA_API_Router::error('invalid_otp', $validation->get_error_message(), 401);
        }

        // Mark OTP as verified.
        $session_data['otp_verified'] = true;
        $store->set($transient_key, $session_data, WCA_Constants::otp_ttl());

        return WCA_API_Router::success([
            'message' => 'Code verified. You can now choose a new password.',
        ]);
    }

    // --- POST /password/reset --------------------------------------------

    public static function complete_reset(WP_REST_Request $request): WP_REST_Response
    {
        $session_token = $request->get_param('session_token');
        $password = $request->get_param('password');

        $transient_key = 'wca_pw_reset_' . $session_token;

        // Fix #4: Retrieve from encrypted store.
        $store = new WCA_Transient_Store();
        $session_data = $store->get($transient_key);

        if (!$session_data || empty($session_data['otp_verified'])) {
            return WCA_API_Router::error('unauthorized', 'You must verify your identity first.', 403);
        }

        $user_id = $session_data['user_id'];

        if (get_user_meta($user_id, WCA_Admin_User_Tools::LOCK_META_KEY, true)) {
            return WCA_API_Router::error(
                'account_locked',
                WCA_Admin_User_Tools::get_suspension_message($user_id),
                403
            );
        }

        // Validate new password complexity.
        if (!WCA_Sanitizer::validate_password_complexity($password)) {
            return WCA_API_Router::error(
                'weak_password',
                'Password must be at least 8 characters and include 1 uppercase letter and 1 number.',
                422
            );
        }

        // Update password.
        wp_set_password($password, $user_id);

        // Fix #4: Delete from encrypted store.
        $store->delete($transient_key);

        WCA_Logger::log('PASSWORD_RESET_COMPLETED', [
            'user_id' => $user_id,
        ]);

        return WCA_API_Router::success([
            'message' => 'Your password has been successfully reset. You can now log in.',
        ]);
    }
}
