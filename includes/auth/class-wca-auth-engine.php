<?php
defined('ABSPATH') || exit;

/**
 * WCA_Auth_Engine - Orchestrates both login variants:
 *   A) Password path
 *   B) OTP path (SMS or email)
 *
 * Key convention for login OTP transients:
 *   wca_login_otp_{session_token}   (user_id stored inside payload, not in key)
 * This allows the resend endpoint to look up the session by session_token alone,
 * since resolve_key() passes any key already prefixed with 'wca_' through directly.
 */
class WCA_Auth_Engine
{

    // --- Password authentication ------------------------------------------

    public static function authenticate_password(WP_User $user, string $password): true|WP_Error
    {
        if (!wp_check_password($password, $user->user_pass, $user->ID)) {
            WCA_Logger::log('AUTH_FAILED', [
                'user_id' => $user->ID,
                'method' => 'password',
                'masked_email' => WCA_Logger::mask_email($user->user_email),
            ]);
            return new WP_Error('invalid_password', 'The password you entered is incorrect.');
        }

        // Apply WP authenticate filter chain (allows other security plugins to hook in).
        $authenticated = apply_filters('authenticate', $user, $user->user_login, $password);
        if (is_wp_error($authenticated)) {
            return $authenticated;
        }

        return true;
    }

    // --- OTP authentication -----------------------------------------------

    /**
     * @param WP_User $user          The user to authenticate.
     * @param string  $otp_code      The code entered by the user.
     * @param string  $session_token The 32-char hex session token returned by dispatch_login_otp().
     */
    public static function authenticate_otp(WP_User $user, string $otp_code, string $session_token): true|WP_Error
    {
        $store = new WCA_Transient_Store();
        // Key uses session_token only - user_id is in the payload.
        $key = 'wca_login_otp_' . $session_token;
        $payload = $store->get($key);

        if (!$payload) {
            return new WP_Error('session_expired', 'Your login session has expired. Please request a new code.');
        }

        // Guard: ensure session belongs to the user claiming to authenticate.
        if ((int) ($payload['user_id'] ?? 0) !== $user->ID) {
            WCA_Logger::log('AUTH_FAILED', [
                'user_id' => $user->ID,
                'method' => 'otp',
                'reason' => 'user_id_mismatch',
            ]);
            return new WP_Error('invalid_session', 'Session does not match the provided identifier.');
        }

        // Attempt counter check.
        if (($payload['attempts'] ?? 0) >= 5) {
            WCA_Logger::log('OTP_MAX_ATTEMPTS', [
                'user_id' => $user->ID,
                'masked_email' => WCA_Logger::mask_email($user->user_email),
            ]);
            $store->delete($key);
            return new WP_Error('max_attempts', 'Too many incorrect attempts. Please request a new code.');
        }

        $validation = WCA_OTP_Validator::validate(
            $otp_code,
            $payload['otp_hash'],
            $payload['otp_expiry']
        );

        if (is_wp_error($validation)) {
            $payload['attempts'] = ($payload['attempts'] ?? 0) + 1;
            $store->set($key, $payload, WCA_Constants::otp_ttl());

            WCA_Logger::log('AUTH_FAILED', [
                'user_id' => $user->ID,
                'method' => 'otp',
                'attempt' => $payload['attempts'],
            ]);
            return $validation;
        }

        // If they successfully verified via SMS, implicitly mark their phone as verified.
        if (($payload['channel'] ?? '') === 'sms') {
            update_user_meta($user->ID, 'billing_phone_verified', 1);
        } else if (($payload['channel'] ?? '') === 'email') {
            update_user_meta($user->ID, 'billing_email_verified', 1);
        }

        // Since they successfully completed an OTP challenge, clear any reverification flags
        // and formally approve the account.
        delete_user_meta($user->ID, 'wca_needs_reverification');
        update_user_meta($user->ID, 'account_status', 'approved');

        $store->delete($key);
        return true;
    }

    // --- Dispatch login OTP -----------------------------------------------

    public static function dispatch_login_otp(WP_User $user, string $channel = 'sms'): array|WP_Error
    {
        $otp_data = WCA_OTP_Generator::generate();
        $session_token = bin2hex(random_bytes(16));
        // Key: wca_login_otp_{session_token} - resend can look this up by session_token alone.
        $key = 'wca_login_otp_' . $session_token;

        $payload = [
            'context' => 'login',
            'user_id' => $user->ID,
            'otp_hash' => $otp_data['hash'],
            'otp_expiry' => $otp_data['expiry'],
            'channel' => $channel,
            'attempts' => 0,
        ];

        $store = new WCA_Transient_Store();
        $stored = $store->set($key, $payload, WCA_Constants::otp_ttl());

        if (!$stored) {
            return new WP_Error('transient_store_failed', 'Failed to create OTP session.');
        }

        if ($channel === 'sms') {
            $phone = get_user_meta($user->ID, 'billing_phone', true);
            if (empty($phone)) {
                $store->delete($key);
                return new WP_Error('no_phone', 'No phone number on file for SMS OTP. Please use email instead.');
            }
            $dispatch = WCA_OTP_Dispatcher::send_login_sms($phone, $otp_data['code'], $user->display_name);
        } else {
            $dispatch = WCA_OTP_Dispatcher::send_login_email($user->user_email, $otp_data['code'], $user->display_name);
        }

        if (is_wp_error($dispatch)) {
            $store->delete($key);
            return $dispatch;
        }

        WCA_Logger::log('OTP_SENT', [
            'user_id' => $user->ID,
            'channel' => $channel,
        ]);

        return ['session_token' => $session_token];
    }
}
