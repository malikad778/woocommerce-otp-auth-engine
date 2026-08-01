<?php
defined('ABSPATH') || exit;

/**
 * WCA_TextMagic_Client - TextMagic REST API wrapper.
 * Enforces GSM-7 encoding and 50-char message cap to guarantee single-credit delivery.
 *
 * Endpoint: POST https://rest.textmagic.com/api/v2/messages
 */
class WCA_TextMagic_Client
{

    private const API_URL = 'https://rest.textmagic.com/api/v2/messages';

    /**
     * GSM-7 basic character set (printable, safe for SMS).
     * Any character NOT in this set triggers SMS_ENCODING_REJECTED.
     */
    private const GSM7_CHARSET = '@$\n\r_\x1B !"#%&\'()*+,-./0123456789:;<=>?ABCDEFGHIJKLMNOPQRSTUVWXYZ'
        . 'abcdefghijklmnopqrstuvwxyz';

    /**
     * Send an SMS OTP via TextMagic.
     *
     * @param string $phone     E.164 formatted phone number.
     * @param string $otp_code  6-digit OTP code.
     *
     * @return true|WP_Error
     */
    public static function send_sms(string $phone, string $otp_code): true|WP_Error
    {
        // 0. Abuse gate. Enforced here rather than (only) in the endpoints so
        //    that every dispatch path - registration, login, resend, password
        //    reset, add-phone, and anything added later - is covered by
        //    construction. An endpoint that forgets to rate-limit can no
        //    longer spend SMS credit.
        $guard = WCA_Rate_Limiter::guard_sms($phone);
        if (is_wp_error($guard)) {
            return $guard;
        }

        // 1. Config check.
        $username = WCA_Constants::textmagic_username();
        $api_key = WCA_Constants::textmagic_api_key();

        if (empty($username) || empty($api_key)) {
            WCA_Logger::log('SMS_CONFIG_MISSING', []);
            return new WP_Error('sms_config_missing', 'TextMagic credentials are not configured.');
        }

        // 2. Build message - strict format, max 160 chars, GSM-7 only.
        $template = get_site_option('wca_sms_otp_template', 'WCA Code: {OTP}. Valid 10 min. Do not share.');
        $message = str_replace('{OTP}', $otp_code, $template);
        // Trim to hard max (160) just in case, to prevent multi-part SMS charges (1 credit = 160 GSM-7 chars).
        $message = substr($message, 0, 160);

        // 3. GSM-7 encoding check.
        if (!self::is_gsm7($message)) {
            WCA_Logger::log('SMS_ENCODING_REJECTED', [
                'masked_phone' => WCA_Logger::mask_phone($phone),
            ]);
            return new WP_Error('sms_encoding_rejected', 'Message contains non-GSM-7 characters.');
        }

        // 4. Dispatch.
        $response = wp_remote_post(self::API_URL, [
            'timeout' => 15,
            'headers' => [
                'X-TM-Username' => $username,
                'X-TM-Key' => $api_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'phones' => $phone,
                'text' => $message,
            ],
        ]);

        if (is_wp_error($response)) {
            WCA_Logger::log('SMS_SEND_FAILED', [
                'masked_phone' => WCA_Logger::mask_phone($phone),
                'error' => $response->get_error_message(),
            ]);
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300) {
            $body = wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);
            $msg = $decoded['message'] ?? "TextMagic returned HTTP {$code}.";

            WCA_Logger::log('SMS_SEND_FAILED', [
                'masked_phone' => WCA_Logger::mask_phone($phone),
                'http_status' => $code,
                'tm_message' => $msg,
            ]);

            return new WP_Error('sms_api_error', 'SMS service is currently unavailable. Please try again later or contact support.');
        }

        WCA_Logger::log('SMS_SENT', [
            'masked_phone' => WCA_Logger::mask_phone($phone),
            'ip_hash' => md5(WCA_Rate_Limiter::get_client_ip() . AUTH_SALT),
        ]);

        return true;
    }

    // --- GSM-7 character whitelist check ---------------------------------

    private static function is_gsm7(string $text): bool
    {
        $gsm7_chars = str_split(self::GSM7_CHARSET);

        for ($i = 0; $i < strlen($text); $i++) {
            if (!in_array($text[$i], $gsm7_chars, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the current TextMagic account balance.
     *
     * @return float|WP_Error
     */
    public static function get_balance(): float|WP_Error
    {
        $username = WCA_Constants::textmagic_username();
        $api_key = WCA_Constants::textmagic_api_key();

        if (empty($username) || empty($api_key)) {
            return new WP_Error('sms_config_missing', 'TextMagic credentials are not configured.');
        }

        $response = wp_remote_get('https://rest.textmagic.com/api/v2/user', [
            'timeout' => 10,
            'headers' => [
                'X-TM-Username' => $username,
                'X-TM-Key' => $api_key,
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            return new WP_Error('sms_api_error', $decoded['message'] ?? "HTTP {$code}");
        }

        return (float) ($decoded['balance'] ?? 0.0);
    }
}
