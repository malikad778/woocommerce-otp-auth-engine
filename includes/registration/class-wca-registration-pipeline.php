<?php
defined('ABSPATH') || exit;

/**
 * WCA_Registration_Pipeline - Orchestrates the three-phase pre-database registration flow.
 *
 * Phase 1: Initiate  - validate  store encrypted transient  dispatch OTP pair
 * Phase 2: Verify    - mark flags in transient (email_verified / sms_verified)
 * Phase 3: Complete  - both flags true  wp_create_user()  issue cookie
 */
class WCA_Registration_Pipeline
{

    // --- Phase 1: Initiate ------------------------------------------------

    public static function initiate(array $data): array|WP_Error
    {
        $validator = new WCA_Registration_Validator();

        // Sanitize + validate all fields.
        $validated = $validator->validate($data);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $store = new WCA_Transient_Store();
        $session_token = $data['session_token'] ?? '';
        $existing_payload = null;

        if (!empty($session_token)) {
            $existing_payload = $store->get(WCA_Constants::transient_registration($session_token));
        }

        if (!$existing_payload) {
            // Generate a new cryptographically secure session token.
            $session_token = bin2hex(random_bytes(32));
        }

        // Hash password immediately - never store plaintext.
        $password_hash = wp_hash_password($validated['password']);

        // Determine if we should preserve verification status (if they didn't change the contact info).
        $email_verified = false;
        $sms_verified = false;
        $send_email = true;
        $send_sms = true;
        $email_link_token = bin2hex(random_bytes(32));
        $email_link_hash = hash('sha256', $email_link_token);

        if ($existing_payload) {
            if ($existing_payload['email'] === $validated['email'] && !empty($existing_payload['email_verified'])) {
                $email_verified = true;
                $send_email = false;
                $email_link_hash = $existing_payload['email_link_token']; // Keep old hash valid just in case
            }
            if ($existing_payload['phone'] === $validated['phone'] && !empty($existing_payload['sms_verified'])) {
                $sms_verified = true;
                $send_sms = false;
            }
        }

        // Build the transient payload.
        $payload = [
            'context' => 'registration',
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password_hash' => $password_hash,
            'email_link_token' => $email_link_hash,
            'email_verified' => $email_verified,
            'sms_verified' => $sms_verified,
            'sms_otp_hash' => $existing_payload['sms_otp_hash'] ?? '',
            'sms_otp_expiry' => $existing_payload['sms_otp_expiry'] ?? 0,
            'sms_otp_attempts' => $existing_payload['sms_otp_attempts'] ?? 0,
            'created_at' => $existing_payload['created_at'] ?? time(),
            'blog_id' => get_current_blog_id(),
            'return_url' => $data['return_url'] ?? '',
        ];

        // IP rate limit, charged only now: validation has passed and an SMS
        // is genuinely about to be dispatched. A mistyped password, a
        // duplicate email, or a resumed session whose phone is already
        // verified therefore costs nothing. An attacker submitting valid
        // data is still stopped on the same request as before.
        if ($send_sms) {
            $ip_check = WCA_Rate_Limiter::check(WCA_Rate_Limiter::get_client_ip());
            if (is_wp_error($ip_check)) {
                return new WP_Error(
                    'rate_limited',
                    'Too many requests. Please wait 15 minutes before trying again.'
                );
            }
        }

        // Generate SMS OTP if we need to send one.
        $otp_code = null;
        if ($send_sms) {
            $otp_data = WCA_OTP_Generator::generate();
            $payload['sms_otp_hash'] = $otp_data['hash'];
            $payload['sms_otp_expiry'] = $otp_data['expiry'];
            $otp_code = $otp_data['code'];
        }

        // Store encrypted transient.
        $stored = $store->set(
            WCA_Constants::transient_registration($session_token),
            $payload,
            WCA_Constants::otp_ttl()
        );

        if (!$stored) {
            return new WP_Error('transient_store_failed', 'Failed to create session. Please try again.');
        }

        $email_dispatch = true;
        if ($send_email) {
            $email_dispatch = WCA_OTP_Dispatcher::send_registration_email(
                $validated['email'],
                $validated['first_name'],
                $email_link_token,
                $session_token
            );
        }

        $sms_dispatch = true;
        if ($send_sms) {
            $sms_dispatch = WCA_OTP_Dispatcher::send_registration_sms(
                $validated['phone'],
                $otp_code
            );

            if (is_wp_error($sms_dispatch)) {
                WCA_Logger::log('OTP_SEND_WARNING', [
                    'step' => 'registration_sms',
                    'error' => $sms_dispatch->get_error_message(),
                ]);
            }
        }

        WCA_Logger::log('REGISTRATION_INITIATED', [
            'masked_email' => WCA_Logger::mask_email($validated['email']),
            'masked_phone' => WCA_Logger::mask_phone($validated['phone']),
            'blog_id' => get_current_blog_id(),
            'resumed' => !empty($existing_payload),
        ]);

        return [
            'session_token' => $session_token,
            'expires_in' => WCA_Constants::otp_ttl(),
            'email_sent' => $send_email && !is_wp_error($email_dispatch),
            'sms_sent' => $send_sms && !is_wp_error($sms_dispatch),
            'email_verified' => $email_verified,
            'sms_verified' => $sms_verified,
            'both_verified' => self::both_verified($payload),
        ];
    }

    // --- Phase 2a: Verify Email -------------------------------------------

    public static function verify_email(string $session_token, string $token): array|WP_Error
    {
        $store = new WCA_Transient_Store();
        $key = WCA_Constants::transient_registration($session_token);
        $payload = $store->get($key);

        if (!$payload) {
            return new WP_Error('session_expired', 'Your verification session has expired. Please register again.');
        }

        if ($payload['email_verified'] ?? false) {
            return ['email_verified' => true, 'both_verified' => self::both_verified($payload), 'return_url' => $payload['return_url'] ?? ''];
        }

        // Constant-time comparison of email link tokens.
        $expected = $payload['email_link_token'] ?? '';
        $submitted = hash('sha256', $token);

        if (!hash_equals($expected, $submitted)) {
            return new WP_Error('invalid_token', 'The verification link is invalid or has already been used.');
        }

        $payload['email_verified'] = true;
        $store->set($key, $payload, WCA_Constants::otp_ttl());

        WCA_Logger::log('EMAIL_VERIFIED', [
            'masked_email' => WCA_Logger::mask_email($payload['email']),
            'blog_id' => $payload['blog_id'] ?? get_current_blog_id(),
        ]);

        return ['email_verified' => true, 'both_verified' => self::both_verified($payload), 'return_url' => $payload['return_url'] ?? ''];
    }

    // --- Phase 2b: Verify SMS ---------------------------------------------

    public static function verify_sms(string $session_token, string $otp_code): array|WP_Error
    {
        $store = new WCA_Transient_Store();
        $key = WCA_Constants::transient_registration($session_token);
        $payload = $store->get($key);

        if (!$payload) {
            return new WP_Error('session_expired', 'Your verification session has expired. Please register again.');
        }

        if ($payload['sms_verified'] ?? false) {
            return ['sms_verified' => true, 'both_verified' => self::both_verified($payload)];
        }

        // Check attempt limit.
        if (($payload['sms_otp_attempts'] ?? 0) >= 5) {
            WCA_Logger::log('OTP_MAX_ATTEMPTS', ['masked_phone' => WCA_Logger::mask_phone($payload['phone'])]);
            return new WP_Error('max_attempts', 'Too many incorrect attempts. Please request a new code.');
        }

        $validation = WCA_OTP_Validator::validate(
            $otp_code,
            $payload['sms_otp_hash'],
            $payload['sms_otp_expiry']
        );

        if (is_wp_error($validation)) {
            // Increment attempt counter.
            $payload['sms_otp_attempts'] = ($payload['sms_otp_attempts'] ?? 0) + 1;
            $store->set($key, $payload, WCA_Constants::otp_ttl());

            if ($payload['sms_otp_attempts'] >= 5) {
                WCA_Logger::log('OTP_MAX_ATTEMPTS', ['masked_phone' => WCA_Logger::mask_phone($payload['phone'])]);
            }

            return $validation;
        }

        $payload['sms_verified'] = true;
        $payload['sms_otp_attempts'] = 0;
        $store->set($key, $payload, WCA_Constants::otp_ttl());

        WCA_Logger::log('SMS_VERIFIED', [
            'masked_phone' => WCA_Logger::mask_phone($payload['phone']),
            'blog_id' => $payload['blog_id'] ?? get_current_blog_id(),
        ]);

        return ['sms_verified' => true, 'both_verified' => self::both_verified($payload)];
    }

    // --- Phase 3: Complete ------------------------------------------------

    public static function complete(string $session_token): array|WP_Error
    {
        $store = new WCA_Transient_Store();
        $key = WCA_Constants::transient_registration($session_token);
        $payload = $store->get($key);

        if (!$payload) {
            return new WP_Error('session_expired', 'Your session has expired.');
        }

        if (!self::both_verified($payload)) {
            return new WP_Error('not_fully_verified', 'Both email and SMS verification are required.');
        }

        $result = WCA_Registration_Completer::create_user($payload, $key);
        if (!is_wp_error($result) && is_array($result)) {
            $result['return_url'] = $payload['return_url'] ?? '';
        }
        return $result;
    }

    // --- Helper -----------------------------------------------------------

    private static function both_verified(array $payload): bool
    {
        return ($payload['email_verified'] ?? false) && ($payload['sms_verified'] ?? false);
    }
}
