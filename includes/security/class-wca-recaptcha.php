<?php
defined('ABSPATH') || exit;

/**
 * WCA_Recaptcha - Server-side reCAPTCHA v3 token validation.
 * Score threshold: 0.5 (configurable in network admin settings).
 *
 * Running WITHOUT keys is a supported configuration, but it is a degraded one:
 * there is then no bot-detection layer at all, and the numeric SMS controls in
 * WCA_Rate_Limiter are the only thing standing between a signup form and the
 * SMS bill. WCA_Rate_Limiter::guard_sms() therefore refuses to dispatch in
 * that mode unless a destination allowlist and a volume ceiling are both in
 * force. See is_configured().
 */
class WCA_Recaptcha
{

    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /**
     * Is reCAPTCHA actually usable? Callers use this to decide how much they
     * must compensate elsewhere, rather than assuming a gate is present.
     */
    public static function is_configured(): bool
    {
        return WCA_Constants::recaptcha_secret_key() !== '';
    }

    /**
     * Verify a reCAPTCHA v3 token.
     *
     * @param string $token  The token submitted from the frontend.
     *
     * @return true|WP_Error  True if valid and score  threshold; WP_Error otherwise.
     */
    public static function verify(string $token): true|WP_Error
    {
        $secret = WCA_Constants::recaptcha_secret_key();
        $fail_open = WCA_Constants::recaptcha_fail_open();

        if (empty($secret)) {
            // No keys: allow the request through rather than locking every
            // customer out of registration and login. There is nothing to
            // "fail closed" to here - an unconfigured check has no verdict.
            // The compensating controls live in WCA_Rate_Limiter::guard_sms().
            //
            // Logged once an hour, not once a request: during an attack this
            // branch runs thousands of times a minute and would otherwise
            // turn a fraud incident into a disk-space incident.
            if (!get_site_transient('wca_recaptcha_unconfigured_logged')) {
                set_site_transient('wca_recaptcha_unconfigured_logged', 1, HOUR_IN_SECONDS);
                WCA_Logger::log('RECAPTCHA_NOT_CONFIGURED', [
                    'note' => 'No secret key set. Running with SMS rate limits and the destination allowlist as the only abuse controls.',
                ]);
            }

            return true;
        }

        if (empty($token)) {
            return new WP_Error('recaptcha_missing', 'reCAPTCHA token is missing.');
        }

        $response = wp_remote_post(self::VERIFY_URL, [
            'timeout' => 10,
            'body' => [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => WCA_Rate_Limiter::get_client_ip(),
            ],
        ]);

        if (is_wp_error($response)) {
            // A verification outage is indistinguishable from a bot flooding
            // us, so it can't be waved through by default: the SMS rate
            // limits behind this gate are the only thing left, and they are
            // per-IP. Operators who would rather accept the abuse risk than
            // the downtime can opt in via the fail-open setting.
            WCA_Logger::log('RECAPTCHA_UNREACHABLE', [
                'error'     => $response->get_error_message(),
                'fail_open' => $fail_open,
            ]);

            if ($fail_open) {
                return true;
            }

            return new WP_Error('recaptcha_unreachable', 'Security check is temporarily unavailable. Please try again shortly.');
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (empty($decoded['success']) || !$decoded['success']) {
            $error_codes = isset($decoded['error-codes']) ? implode(', ', $decoded['error-codes']) : 'unknown';
            return new WP_Error('recaptcha_failed', "reCAPTCHA verification failed. Reason: {$error_codes}");
        }

        $score = (float) ($decoded['score'] ?? 0.0);
        $threshold = WCA_Constants::recaptcha_threshold();

        if ($score < $threshold) {
            return new WP_Error('recaptcha_low_score', "reCAPTCHA score {$score} below threshold {$threshold}.");
        }

        return true;
    }
}
