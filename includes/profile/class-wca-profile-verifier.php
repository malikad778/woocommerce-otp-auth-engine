<?php
defined('ABSPATH') || exit;

/**
 * WCA_Profile_Verifier - Manages the pending-state dual-verification loop for
 * profile email/phone updates. Old values remain active until both are verified.
 */
class WCA_Profile_Verifier
{

    // --- Initiate ---------------------------------------------------------

    public static function initiate(int $user_id, ?string $new_email, ?string $new_phone): true|WP_Error
    {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.');
        }

        $payload = [
            'context' => 'profile_update',
            'user_id' => $user_id,
            'new_email' => $new_email,
            'new_phone' => $new_phone,
            'email_verified' => $new_email ? false : true,  // No verification needed if not changing.
            'sms_verified' => $new_phone ? false : true,
            'email_otp_hash' => '',
            'email_otp_expiry' => 0,
            'sms_otp_hash' => '',
            'sms_otp_expiry' => 0,
            'email_otp_attempts' => 0,
            'sms_otp_attempts' => 0,
            'created_at' => time(),
        ];

        // Generate OTPs for channels that need verification.
        if ($new_email) {
            $email_otp = WCA_OTP_Generator::generate();
            $payload['email_otp_hash'] = $email_otp['hash'];
            $payload['email_otp_expiry'] = $email_otp['expiry'];
        }

        if ($new_phone) {
            $sms_otp = WCA_OTP_Generator::generate();
            $payload['sms_otp_hash'] = $sms_otp['hash'];
            $payload['sms_otp_expiry'] = $sms_otp['expiry'];
        }

        // Store encrypted transient.
        $store = new WCA_Transient_Store();
        $key = WCA_Constants::transient_profile_update($user_id);
        $store->set($key, $payload, WCA_Constants::otp_ttl());

        // Dispatch OTPs.
        if ($new_email) {
            WCA_OTP_Dispatcher::send_profile_email($new_email, $email_otp['code'], $user->display_name);
        }

        if ($new_phone) {
            WCA_OTP_Dispatcher::send_profile_sms($new_phone, $sms_otp['code']);
        }

        WCA_Logger::log('PROFILE_UPDATE_INITIATED', [
            'user_id' => $user_id,
            'channels' => array_filter([$new_email ? 'email' : null, $new_phone ? 'sms' : null]),
            'masked_email' => $new_email ? WCA_Logger::mask_email($new_email) : null,
            'masked_phone' => $new_phone ? WCA_Logger::mask_phone($new_phone) : null,
        ]);

        return true;
    }

    // --- Verify (per channel) ---------------------------------------------

    public static function verify(int $user_id, string $channel, string $otp_code): array|WP_Error
    {
        $store = new WCA_Transient_Store();
        $key = WCA_Constants::transient_profile_update($user_id);
        $payload = $store->get($key);

        if (!$payload) {
            return new WP_Error('session_expired', 'Your verification session has expired.');
        }

        if ($channel === 'email') {
            $attempt_key = 'email_otp_attempts';
            $hash_key = 'email_otp_hash';
            $expiry_key = 'email_otp_expiry';
            $verified_key = 'email_verified';
        } else {
            $attempt_key = 'sms_otp_attempts';
            $hash_key = 'sms_otp_hash';
            $expiry_key = 'sms_otp_expiry';
            $verified_key = 'sms_verified';
        }

        // Already verified - idempotent.
        if ($payload[$verified_key]) {
            $committed = self::maybe_commit($payload, $store, $key, $user_id);
            return $committed ? ['committed' => true] : ['verified' => true];
        }

        // Attempt limit.
        if (($payload[$attempt_key] ?? 0) >= 5) {
            WCA_Logger::log('OTP_MAX_ATTEMPTS', ['user_id' => $user_id, 'channel' => $channel]);
            return new WP_Error('max_attempts', 'Too many incorrect attempts. Please restart the verification.');
        }

        $validation = WCA_OTP_Validator::validate(
            $otp_code,
            $payload[$hash_key],
            $payload[$expiry_key]
        );

        if (is_wp_error($validation)) {
            $payload[$attempt_key]++;
            $store->set($key, $payload, WCA_Constants::otp_ttl());
            return $validation;
        }

        $payload[$verified_key] = true;
        $store->set($key, $payload, WCA_Constants::otp_ttl());

        // Check if both channels are now verified.
        $committed = self::maybe_commit($payload, $store, $key, $user_id);

        return $committed ? ['committed' => true] : ['verified' => true];
    }

    // --- Commit to DB when both verified ---------------------------------

    private static function maybe_commit(
        array $payload,
        WCA_Transient_Store $store,
        string $key,
        int $user_id
    ): bool {
        if (!($payload['email_verified'] && $payload['sms_verified'])) {
            return false;
        }

        $current_user = get_user_by('id', $user_id);
        $current_email = $current_user->user_email;
        $current_phone = get_user_meta($user_id, 'billing_phone', true);

        // Commit email: update value only if it actually changed, but always mark verified.
        if (!empty($payload['new_email'])) {
            if ($payload['new_email'] !== $current_email) {
                wp_update_user(['ID' => $user_id, 'user_email' => $payload['new_email']]);
                update_user_meta($user_id, 'billing_email', $payload['new_email']);
            }
            update_user_meta($user_id, 'billing_email_verified', 1);
        }

        // Commit phone: update value only if it actually changed, but always mark verified.
        if (!empty($payload['new_phone'])) {
            if ($payload['new_phone'] !== $current_phone) {
                update_user_meta($user_id, 'billing_phone', $payload['new_phone']);
            }
            update_user_meta($user_id, 'billing_phone_verified', 1);
        }

        $store->delete($key);

        // Clear any stale reverification flag and ensure the account is approved.
        // This handles legacy accounts that were stuck in a loop because maybe_commit()
        // never cleared wca_needs_reverification after a successful email/phone verification.
        delete_user_meta($user_id, 'wca_needs_reverification');
        update_user_meta($user_id, 'account_status', 'approved');

        WCA_Logger::log('PROFILE_UPDATE_COMMITTED', [
            'user_id' => $user_id,
            'masked_email' => !empty($payload['new_email']) ? WCA_Logger::mask_email($payload['new_email']) : null,
            'masked_phone' => !empty($payload['new_phone']) ? WCA_Logger::mask_phone($payload['new_phone']) : null,
        ]);

        return true;
    }
}
