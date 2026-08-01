<?php
defined('ABSPATH') || exit;

/**
 * WCA_Endpoint_Profile - Handles /profile/* REST routes.
 */
class WCA_Endpoint_Profile
{

    // --- Permission callback (nonce + logged-in) --------------------------

    public static function require_auth(WP_REST_Request $request): bool|WP_Error
    {
        if (!is_user_logged_in()) {
            return new WP_Error('unauthorized', 'You must be logged in.', ['status' => 401]);
        }

        $nonce = $request->get_header('X-WP-Nonce');
        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('invalid_nonce', 'Security token invalid or expired.', ['status' => 403]);
        }

        return true;
    }

    // --- POST /profile/update-initiate -----------------------------------

    public static function update_initiate(WP_REST_Request $request): WP_REST_Response
    {
        $user_id = get_current_user_id();
        $new_email = $request->get_param('email');
        $new_phone = $request->get_param('phone');

        if (empty($new_email) && empty($new_phone)) {
            return WCA_API_Router::error('nothing_to_update', 'Provide a new email or phone number to update.', 422);
        }

        // reCAPTCHA gate.
        $rc = WCA_Recaptcha::verify($request->get_param('recaptcha_token'));
        if (is_wp_error($rc)) {
            return WCA_API_Router::error('recaptcha_failed', 'Security check failed.', 403);
        }

        $user = get_user_by('id', $user_id);
        $current_email = $user->user_email;
        $current_phone = get_user_meta($user_id, 'billing_phone', true);

        $email_verified = (bool) get_user_meta($user_id, 'billing_email_verified', true);
        $phone_verified = (bool) get_user_meta($user_id, 'billing_phone_verified', true);

        // Validate new email if provided.
        if (!empty($new_email)) {
            $new_email = WCA_Sanitizer::sanitize_email($new_email);
            if (!$new_email) {
                return WCA_API_Router::error('invalid_email', 'Please enter a valid email address.', 422);
            }
            if ($new_email === $current_email) {
                // Same value: only proceed if email is unverified (user wants to verify existing).
                if ($email_verified) {
                    $new_email = null; // Already verified and unchanged - no action needed.
                }
                // else: keep $new_email so the verifier sends an OTP to confirm existing address.
            } else {
                // Changing to a different email - check availability.
                if (email_exists($new_email)) {
                    return WCA_API_Router::error('email_exists', 'That email address is already registered.', 409);
                }
            }
        }

        // Validate new phone if provided.
        if (!empty($new_phone)) {
            $new_phone = WCA_Sanitizer::normalize_phone($new_phone);
            if (!$new_phone) {
                return WCA_API_Router::error('invalid_phone', 'Please enter a valid phone number.', 422);
            }
            if ($new_phone === $current_phone) {
                // Same value: only proceed if phone is unverified.
                if ($phone_verified) {
                    $new_phone = null; // Already verified and unchanged.
                }
                // else: keep $new_phone so the verifier sends an OTP to confirm existing number.
            }
        }

        if (empty($new_email) && empty($new_phone)) {
            return WCA_API_Router::error('nothing_to_update', 'The new values are the same as your current contact details.', 422);
        }

        $result = WCA_Profile_Verifier::initiate($user_id, $new_email, $new_phone);

        if (is_wp_error($result)) {
            return WCA_API_Router::error($result->get_error_code(), $result->get_error_message(), 500);
        }

        $channels = [];
        if ($new_email)
            $channels[] = 'email';
        if ($new_phone)
            $channels[] = 'sms';

        return WCA_API_Router::success([
            'initiated' => true,
            'channels' => $channels,
            'expires_in' => WCA_Constants::otp_ttl(),
            'message' => 'Verification codes sent. Please verify your new contact details.',
        ]);
    }

    // --- POST /profile/verify-update -------------------------------------

    public static function verify_update(WP_REST_Request $request): WP_REST_Response
    {
        $user_id = get_current_user_id();
        $channel = $request->get_param('channel');
        $otp_code = $request->get_param('otp_code');

        $result = WCA_Profile_Verifier::verify($user_id, $channel, $otp_code);

        if (is_wp_error($result)) {
            return WCA_API_Router::error($result->get_error_code(), $result->get_error_message(), 422);
        }

        if (!empty($result['committed'])) {
            return WCA_API_Router::success([
                'committed' => true,
                'message' => 'Your contact details have been updated successfully.',
                'redirect' => wc_get_page_permalink('myaccount'),
            ]);
        }

        return WCA_API_Router::success([
            'verified' => true,
            'channel' => $channel,
            'message' => ucfirst($channel) . ' verified. Please also verify your ' . ($channel === 'email' ? 'phone' : 'email') . '.',
        ]);
    }
}
