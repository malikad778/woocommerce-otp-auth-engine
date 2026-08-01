<?php
defined('ABSPATH') || exit;

/**
 * WCA_OTP_Dispatcher - Routes OTP dispatch to WCA_Email_Client (email) or TextMagic (SMS).
 */
class WCA_OTP_Dispatcher
{

    // --- Registration: Email verification link ----------------------------

    public static function send_registration_email(
        string $email,
        string $first_name,
        string $raw_token,
        string $session_token
    ): true|WP_Error {
        $verify_url = add_query_arg([
            'wca_action' => 'verify_email',
            'session_token' => rawurlencode($session_token),
            'token' => rawurlencode($raw_token),
        ], site_url('/my-account/'));

        $result = WCA_Email_Client::send([
            'to' => $email,
            'to_name' => $first_name,
            'template_key' => 'registration',
            'params' => [
                'FIRST_NAME' => $first_name,
                'VERIFY_URL' => esc_url($verify_url),
                'EXPIRY_MINUTES' => (int) (WCA_Constants::otp_ttl() / 60),
            ],
        ]);

        if (is_wp_error($result)) {
            WCA_Logger::log('EMAIL_SEND_FAILED', [
                'masked_email' => WCA_Logger::mask_email($email),
                'step' => 'registration_verify',
                'error' => $result->get_error_message(),
            ]);
            return $result;
        }

        WCA_Logger::log('OTP_SENT', [
            'channel' => 'email',
            'masked_email' => WCA_Logger::mask_email($email),
            'step' => 'registration',
        ]);

        return true;
    }

    // --- Registration: SMS OTP --------------------------------------------

    public static function send_registration_sms(string $phone, string $otp_code): true|WP_Error
    {
        $result = WCA_TextMagic_Client::send_sms($phone, $otp_code);

        if (is_wp_error($result)) {
            return $result;
        }

        WCA_Logger::log('OTP_SENT', [
            'channel' => 'sms',
            'masked_phone' => WCA_Logger::mask_phone($phone),
            'step' => 'registration',
        ]);

        return true;
    }

    // --- Login: SMS OTP ---------------------------------------------------

    public static function send_login_sms(string $phone, string $otp_code, string $display_name = ''): true|WP_Error
    {
        $result = WCA_TextMagic_Client::send_sms($phone, $otp_code);

        if (is_wp_error($result)) {
            return $result;
        }

        WCA_Logger::log('OTP_SENT', [
            'channel' => 'sms',
            'masked_phone' => WCA_Logger::mask_phone($phone),
            'step' => 'login',
        ]);

        return true;
    }

    // --- Login: Email OTP -------------------------------------------------

    public static function send_login_email(string $email, string $otp_code, string $display_name = ''): true|WP_Error
    {
        $result = WCA_Email_Client::send([
            'to' => $email,
            'to_name' => $display_name,
            'template_key' => 'login',
            'params' => [
                'FIRST_NAME' => $display_name,
                'OTP_CODE' => $otp_code,
                'EXPIRY_MINUTES' => (int) (WCA_Constants::otp_ttl() / 60),
            ],
        ]);

        if (is_wp_error($result)) {
            WCA_Logger::log('EMAIL_SEND_FAILED', [
                'masked_email' => WCA_Logger::mask_email($email),
                'step' => 'login_otp',
                'error' => $result->get_error_message(),
            ]);
            return $result;
        }

        WCA_Logger::log('OTP_SENT', [
            'channel' => 'email',
            'masked_email' => WCA_Logger::mask_email($email),
            'step' => 'login',
        ]);

        return true;
    }

    // --- Profile update: Email verification -------------------------------

    public static function send_profile_email(string $new_email, string $otp_code, string $display_name): true|WP_Error
    {
        $result = WCA_Email_Client::send([
            'to' => $new_email,
            'to_name' => $display_name,
            'template_key' => 'profile_update',
            'params' => [
                'FIRST_NAME' => $display_name,
                'OTP_CODE' => $otp_code,
                'EXPIRY_MINUTES' => (int) (WCA_Constants::otp_ttl() / 60),
            ],
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        WCA_Logger::log('OTP_SENT', [
            'channel' => 'email',
            'masked_email' => WCA_Logger::mask_email($new_email),
            'step' => 'profile_update',
        ]);

        return true;
    }

    // --- Profile update: SMS verification --------------------------------

    public static function send_password_forgot_email(string $email, string $otp_code, string $display_name = ''): true|WP_Error
    {
        $result = WCA_Email_Client::send([
            'to' => $email,
            'to_name' => $display_name,
            'template_key' => 'forgot_password',
            'params' => [
                'FIRST_NAME' => $display_name,
                'OTP_CODE' => $otp_code,
                'EXPIRY_MINUTES' => (int) (WCA_Constants::otp_ttl() / 60),
            ],
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        WCA_Logger::log('OTP_SENT', [
            'channel' => 'email',
            'masked_email' => WCA_Logger::mask_email($email),
            'step' => 'password_forgot',
        ]);

        return true;
    }

    // --- Profile update: SMS verification --------------------------------

    public static function send_profile_sms(string $new_phone, string $otp_code): true|WP_Error
    {
        return self::send_registration_sms($new_phone, $otp_code);
    }

    // --- Resend: Registration ---------------------------------------------

    public static function resend_registration_otp(
        array $payload,
        string $session_token,
        string $channel
    ): true|WP_Error {
        $otp_data = WCA_OTP_Generator::generate();
        $store = new WCA_Transient_Store();
        $key = WCA_Constants::transient_registration($session_token);

        if ($channel === 'sms') {
            // Invalidate previous OTP immediately on resend
            $payload['sms_otp_hash'] = '';
            $payload['sms_otp_expiry'] = 0;
            $payload['sms_otp_attempts'] = 0;
            $store->set($key, $payload, WCA_Constants::otp_ttl());

            $payload['sms_otp_hash'] = $otp_data['hash'];
            $payload['sms_otp_expiry'] = $otp_data['expiry'];
            $payload['sms_otp_attempts'] = 0;
            $store->set($key, $payload, WCA_Constants::otp_ttl());
            return self::send_registration_sms($payload['phone'], $otp_data['code']);
        }

        // Email resend: regenerate the link token.
        $new_token = bin2hex(random_bytes(32));
        $payload['email_link_token'] = hash('sha256', $new_token);
        $store->set($key, $payload, WCA_Constants::otp_ttl());

        return self::send_registration_email(
            $payload['email'],
            $payload['first_name'],
            $new_token,
            $session_token
        );
    }

    // --- Resend: Login OTP ------------------------------------------------

    /**
     * Regenerates the OTP for an existing login session and re-dispatches it.
     *
     * Key convention: wca_login_otp_{session_token}
     * (matches dispatch_login_otp() in WCA_Auth_Engine)
     *
     * @param WP_User $user
     * @param array   $payload       Current decrypted transient payload.
     * @param string  $session_token The session token (used to build the transient key).
     * @param string  $channel       'sms' or 'email'.
     */
    public static function resend_login_otp(
        WP_User $user,
        array $payload,
        string $session_token,
        string $channel
    ): true|WP_Error {
        $otp_data = WCA_OTP_Generator::generate();
        // Key must match dispatch_login_otp(): wca_login_otp_{session_token}
        $key = 'wca_login_otp_' . $session_token;

        // Invalidate previous OTP immediately on resend
        $payload['otp_hash'] = '';
        $payload['otp_expiry'] = 0;
        $payload['attempts'] = 0;
        (new WCA_Transient_Store())->set($key, $payload, WCA_Constants::otp_ttl());

        $payload['otp_hash'] = $otp_data['hash'];
        $payload['otp_expiry'] = $otp_data['expiry'];
        $payload['attempts'] = 0;
        $payload['channel'] = $channel;

        (new WCA_Transient_Store())->set($key, $payload, WCA_Constants::otp_ttl());

        if ($channel === 'sms') {
            $phone = get_user_meta($user->ID, 'billing_phone', true);
            if (empty($phone)) {
                return new WP_Error('no_phone', 'No phone number on file. Please use email instead.');
            }
            return self::send_login_sms($phone, $otp_data['code'], $user->display_name);
        }

        return self::send_login_email($user->user_email, $otp_data['code'], $user->display_name);
    }
}
