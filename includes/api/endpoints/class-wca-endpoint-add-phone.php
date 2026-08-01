<?php
defined('ABSPATH') || exit;

/**
 * WCA_Endpoint_Add_Phone
 *
 * Handles the two-step flow for legacy users who have no billing_phone.
 *
 *   POST /profile/add-phone/send-otp    -- validate number, send SMS OTP
 *   POST /profile/add-phone/verify-otp  -- verify OTP, save phone + mark verified
 *
 * Uses WCA_Sanitizer::normalize_phone() (same as registration/profile),
 * WCA_Transient_Store for AES-256-encrypted session storage,
 * and WCA_TextMagic_Client::send_sms() for dispatch.
 */
class WCA_Endpoint_Add_Phone
{

    // -- POST /profile/add-phone/send-otp ---------------------------------

    public static function send_otp(WP_REST_Request $request): WP_REST_Response
    {
        // reCAPTCHA gate
        $rc = WCA_Recaptcha::verify($request->get_param('recaptcha_token'));
        if (is_wp_error($rc)) {
            return WCA_API_Router::error('recaptcha_failed', 'Security check failed.', 403);
        }

        // IP rate limit
        $ip_check = WCA_Rate_Limiter::check(WCA_Rate_Limiter::get_client_ip());
        if (is_wp_error($ip_check)) {
            return WCA_API_Router::error('rate_limited', 'Too many requests. Please wait 15 minutes.', 429);
        }

        $raw_phone = sanitize_text_field($request->get_param('phone'));

        // Normalize using the same method used everywhere else in the plugin
        $phone = WCA_Sanitizer::normalize_phone($raw_phone);
        if ($phone === false) {
            return WCA_API_Router::error('invalid_phone', 'Please enter a valid phone number including your country code (e.g. +447911123456).', 422);
        }

        // Make sure the number isn't already claimed by another account
        $existing = get_users([
            'meta_key' => 'billing_phone',
            'meta_value' => $phone,
            'number' => 1,
            'fields' => 'ids',
            'exclude' => [get_current_user_id()],
        ]);
        if (!empty($existing)) {
            return WCA_API_Router::error('phone_taken', 'That phone number is already linked to another account.', 409);
        }

        // Generate OTP & encrypted session
        $session_token = bin2hex(random_bytes(32));
        $transient_key = 'wca_add_phone_' . $session_token;
        $otp_data = WCA_OTP_Generator::generate();
        $expires_in = WCA_Constants::otp_ttl();

        $session_data = [
            'user_id' => get_current_user_id(),
            'phone' => $phone,
            'otp_hash' => $otp_data['hash'],
            'expires' => $otp_data['expiry'],
        ];

        $store = new WCA_Transient_Store();
        $store->set($transient_key, $session_data, $expires_in);

        // Send SMS via TextMagic
        $sms_result = WCA_TextMagic_Client::send_sms($phone, $otp_data['code']);
        if (is_wp_error($sms_result)) {
            $store->delete($transient_key);
            return WCA_API_Router::error(
                'sms_failed',
                'Could not send SMS. Please check your number and try again.',
                500
            );
        }

        WCA_Logger::log('OTP_SENT', [
            'channel' => 'sms',
            'masked_phone' => WCA_Logger::mask_phone($phone),
            'step' => 'add_phone',
        ]);

        return new WP_REST_Response([
            'success' => true,
            'session_token' => $session_token,
            'expires_in' => $expires_in,
        ], 200);
    }

    // -- POST /profile/add-phone/verify-otp -------------------------------

    public static function verify_otp(WP_REST_Request $request): WP_REST_Response
    {
        $session_token = sanitize_text_field($request->get_param('session_token'));
        $otp_code = sanitize_text_field($request->get_param('otp_code'));

        $transient_key = 'wca_add_phone_' . $session_token;

        $store = new WCA_Transient_Store();
        $session_data = $store->get($transient_key);

        if (!$session_data) {
            return WCA_API_Router::error('session_expired', 'Your code has expired. Please request a new one.', 410);
        }

        // Ensure the session belongs to the current user
        if ((int) $session_data['user_id'] !== get_current_user_id()) {
            return WCA_API_Router::error('forbidden', 'Session mismatch.', 403);
        }

        // Validate OTP (enforces TTL via WCA_OTP_Validator)
        $validation = WCA_OTP_Validator::validate(
            $otp_code,
            $session_data['otp_hash'],
            $session_data['expires']
        );

        if (is_wp_error($validation)) {
            return WCA_API_Router::error('invalid_otp', $validation->get_error_message(), 422);
        }

        // All good -- save phone and mark it verified
        $user_id = get_current_user_id();
        $phone = $session_data['phone'];

        update_user_meta($user_id, 'billing_phone', $phone);
        update_user_meta($user_id, 'billing_phone_verified', '1');

        // Clear any reverification flag and fully approve the account
        delete_user_meta($user_id, 'wca_needs_reverification');
        update_user_meta($user_id, 'account_status', 'approved');

        $store->delete($transient_key);

        WCA_Logger::log('PHONE_ADDED', [
            'user_id' => $user_id,
            'masked_phone' => WCA_Logger::mask_phone($phone),
        ]);

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Your phone number has been verified.', 'wca-auth-engine'),
        ], 200);
    }
}
