<?php
defined( 'ABSPATH' ) || exit;

/**
 * WCA_Rate_Limiter - Abuse controls for every SMS-sending path.
 *
 * Four independent gates, all enforced before a single SMS leaves the site:
 *
 *   1. Kill switch          - one setting stops all SMS dispatch instantly.
 *   2. Per client IP        - blocks a single host hammering an endpoint.
 *   3. Per destination MSISDN - blocks an SMS-pumping bot that rotates IPs but
 *      keeps requesting codes for the same revenue-share number.
 *   4. Network-wide         - a circuit breaker capping total spend per hour,
 *      however distributed the attack is.
 *
 * Gates 3 and 4 exist because gate 2 alone is defeated by any botnet, and was
 * previously defeated by a single spoofed X-Forwarded-For header. See
 * get_client_ip() for why proxy headers are no longer trusted by default.
 *
 * All counters use site transients so they are shared network-wide on
 * multisite - a per-subsite counter would multiply the attacker's budget by
 * the number of sites in the network.
 */
class WCA_Rate_Limiter {

    /**
     * Check whether the given IP is within the per-IP rate limit.
     * Increments the counter on each call.
     *
     * @param string $ip  Client IP address (raw, not hashed).
     *
     * @return true|WP_Error  True if within limit; WP_Error if exceeded.
     */
    public static function check( string $ip ): true|WP_Error {
        $limit  = WCA_Constants::sms_rate_limit();
        $window = WCA_Constants::sms_rate_window();

        if ( self::hit( WCA_Constants::transient_rate_limit( $ip ), $limit, $window ) ) {
            return true;
        }

        WCA_Logger::log( 'OTP_RATE_LIMITED', [
            'scope'   => 'ip',
            'ip_hash' => md5( $ip . AUTH_SALT ),
            'limit'   => $limit,
            'window'  => $window,
        ] );

        return new WP_Error( 'rate_limited', 'Rate limit exceeded.' );
    }

    /**
     * Check whether this destination number has been sent too many codes.
     *
     * This is the gate that actually stops SMS pumping: the attacker controls
     * the source IP but not the fact that they must name a destination number
     * to earn revenue on it.
     *
     * @param string $phone  E.164 destination number.
     */
    public static function check_phone( string $phone ): true|WP_Error {
        $limit  = WCA_Constants::sms_phone_limit();
        $window = WCA_Constants::sms_phone_window();

        if ( self::hit( WCA_Constants::transient_phone_limit( $phone ), $limit, $window ) ) {
            return true;
        }

        WCA_Logger::log( 'SMS_PHONE_RATE_LIMITED', [
            'scope'        => 'phone',
            'masked_phone' => WCA_Logger::mask_phone( $phone ),
            'limit'        => $limit,
            'window'       => $window,
        ] );

        return new WP_Error( 'phone_rate_limited', 'Too many codes have been requested for that number. Please try again later.' );
    }

    /**
     * Network-wide circuit breaker on total SMS volume.
     *
     * Bounds the blast radius of anything this class hasn't anticipated: no
     * matter how many IPs or numbers an attacker rotates through, the site
     * cannot spend more than this many SMS credits per window.
     */
    public static function check_global(): true|WP_Error {
        $limit  = self::effective_global_limit();
        $window = WCA_Constants::sms_global_window();

        if ( $limit <= 0 ) {
            return true; // Circuit breaker disabled.
        }

        if ( self::hit( 'wca_rl_global_sms', $limit, $window ) ) {
            return true;
        }

        // Log once per window rather than once per blocked request, so a
        // sustained attack doesn't itself become a disk-fill problem.
        if ( ! get_site_transient( 'wca_rl_global_notified' ) ) {
            set_site_transient( 'wca_rl_global_notified', 1, $window );
            WCA_Logger::log( 'SMS_GLOBAL_CIRCUIT_BREAKER', [
                'limit'  => $limit,
                'window' => $window,
                'note'   => 'Network-wide SMS cap reached. All SMS dispatch is paused for the rest of this window.',
            ] );
        }

        return new WP_Error( 'sms_paused', 'SMS delivery is temporarily unavailable. Please try again later or use email.' );
    }

    /**
     * Single composite gate for every SMS dispatch. Called by
     * WCA_TextMagic_Client::send_sms() so no code path can skip it, whatever
     * the calling endpoint does or forgets to do.
     *
     * @param string $phone  E.164 destination number.
     */
    public static function guard_sms( string $phone ): true|WP_Error {
        if ( ! WCA_Constants::sms_enabled() ) {
            WCA_Logger::log( 'SMS_DISABLED', [
                'masked_phone' => WCA_Logger::mask_phone( $phone ),
            ] );
            return new WP_Error( 'sms_disabled', 'SMS delivery is currently disabled. Please use email verification.' );
        }

        // Running without reCAPTCHA is supported, but not with the
        // destination list also wide open: that combination is precisely the
        // configuration that was exploited - anyone, anywhere, unlimited
        // destinations. One of the two must be in place.
        if ( ! WCA_Recaptcha::is_configured() && empty( WCA_Constants::sms_allowed_countries() ) ) {
            WCA_Logger::log( 'SMS_BLOCKED_NO_CONTROLS', [
                'masked_phone' => WCA_Logger::mask_phone( $phone ),
                'note'         => 'reCAPTCHA is not configured and the destination allowlist is empty. Set wca_sms_allowed_countries (e.g. 44) or configure reCAPTCHA keys.',
            ] );
            return new WP_Error( 'sms_unconfigured', 'SMS delivery is currently unavailable. Please use email verification.' );
        }

        if ( ! WCA_Sanitizer::is_allowed_destination( $phone ) ) {
            WCA_Logger::log( 'SMS_COUNTRY_BLOCKED', [
                'masked_phone' => WCA_Logger::mask_phone( $phone ),
                'ip_hash'      => md5( self::get_client_ip() . AUTH_SALT ),
            ] );
            return new WP_Error( 'phone_country_blocked', 'We cannot send verification codes to that country.' );
        }

        $phone_check = self::check_phone( $phone );
        if ( is_wp_error( $phone_check ) ) {
            return $phone_check;
        }

        return self::check_global();
    }

    /**
     * Single composite gate for every outbound email, mirroring guard_sms().
     * Called by WCA_Email_Client::send().
     *
     * The July incident sent 1,021 SMS and 1,021 *emails* - registration
     * dispatches both - but only the SMS side was visible on an invoice. An
     * email flood costs nothing per message and so gets noticed late, by
     * which point the sending domain is throttled and order confirmations
     * have stopped arriving.
     *
     * @param string $email  Recipient address.
     */
    public static function guard_email( string $email ): true|WP_Error {
        $limit  = WCA_Constants::email_recipient_limit();
        $window = WCA_Constants::email_recipient_window();

        if ( ! self::hit( WCA_Constants::transient_email_limit( $email ), $limit, $window ) ) {
            WCA_Logger::log( 'EMAIL_RECIPIENT_RATE_LIMITED', [
                'masked_email' => WCA_Logger::mask_email( $email ),
                'limit'        => $limit,
                'window'       => $window,
            ] );
            return new WP_Error( 'email_rate_limited', 'Too many emails have been sent to that address. Please try again later.' );
        }

        $global_limit  = WCA_Constants::email_global_limit();
        $global_window = WCA_Constants::email_global_window();

        if ( $global_limit <= 0 ) {
            return true;
        }

        if ( ! self::hit( 'wca_rl_global_email', $global_limit, $global_window ) ) {
            if ( ! get_site_transient( 'wca_rl_global_email_notified' ) ) {
                set_site_transient( 'wca_rl_global_email_notified', 1, $global_window );
                WCA_Logger::log( 'EMAIL_GLOBAL_CIRCUIT_BREAKER', [
                    'limit'  => $global_limit,
                    'window' => $global_window,
                    'note'   => 'Network-wide email cap reached. Outbound plugin email is paused for the rest of this window.',
                ] );
            }
            return new WP_Error( 'email_paused', 'Email delivery is temporarily unavailable. Please try again later.' );
        }

        return true;
    }

    /**
     * Get the client's real IP.
     *
     * Proxy headers (X-Forwarded-For, CF-Connecting-IP, X-Real-IP) are
     * attacker-controlled: anyone can send a different one on every request.
     * Trusting them unconditionally - as this method used to - gave every
     * request its own fresh rate-limit bucket, making check() a no-op.
     *
     * They are therefore only read when the operator has confirmed the site
     * actually sits behind a proxy that overwrites them, via the
     * "Trusted Proxy Header" setting or the WCA_TRUSTED_PROXY_HEADER constant.
     * Otherwise REMOTE_ADDR - the only value the attacker cannot forge - wins.
     */
    public static function get_client_ip(): string {
        $remote_addr = self::valid_ip( $_SERVER['REMOTE_ADDR'] ?? '' );

        $trusted = self::trusted_proxy_header();

        if ( $trusted !== '' ) {
            $forwarded = $_SERVER[ $trusted ] ?? '';

            // X-Forwarded-For can be a comma-separated chain. The left-most
            // entry is the client, but only the right-most entries were
            // written by infrastructure we control - so take the last hop we
            // did not append ourselves: the first value is still the correct
            // choice for a single trusted proxy, which is the supported setup.
            if ( str_contains( (string) $forwarded, ',' ) ) {
                $forwarded = trim( explode( ',', $forwarded )[0] );
            }

            $forwarded = self::valid_ip( (string) $forwarded );

            if ( $forwarded !== '' ) {
                return $forwarded;
            }
        }

        return $remote_addr !== '' ? $remote_addr : '0.0.0.0';
    }

    // --- Internals --------------------------------------------------------

    /**
     * Hard ceiling applied when the operator has switched the circuit breaker
     * off but there is no bot-detection layer either. Generous enough not to
     * interfere with real signup volume, small enough that an unnoticed
     * incident costs tens of messages rather than thousands.
     */
    private const UNPROTECTED_GLOBAL_FALLBACK = 100;

    /**
     * The network-wide cap actually in force.
     *
     * "Disabled" is a reasonable operator choice while reCAPTCHA is filtering
     * bots. With reCAPTCHA off it means an unbounded bill, so the shipped
     * default is reinstated rather than honoured as unlimited.
     */
    private static function effective_global_limit(): int {
        $limit = WCA_Constants::sms_global_limit();

        if ( $limit > 0 ) {
            return $limit;
        }

        return WCA_Recaptcha::is_configured() ? 0 : self::UNPROTECTED_GLOBAL_FALLBACK;
    }

    /**
     * Which proxy header, if any, the operator has declared trustworthy.
     * Returns a $_SERVER key ('HTTP_CF_CONNECTING_IP') or '' for none.
     */
    private static function trusted_proxy_header(): string {
        if ( defined( 'WCA_TRUSTED_PROXY_HEADER' ) && WCA_TRUSTED_PROXY_HEADER ) {
            $header = (string) WCA_TRUSTED_PROXY_HEADER;
        } else {
            $header = WCA_Constants::trusted_proxy_header();
        }

        $header = strtoupper( str_replace( '-', '_', trim( $header ) ) );

        if ( $header === '' ) {
            return '';
        }

        // Only these three are meaningful, and each must be spelled as its
        // $_SERVER key so an operator typo can't widen what we trust.
        $allowed = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ];

        if ( ! str_starts_with( $header, 'HTTP_' ) ) {
            $header = 'HTTP_' . $header;
        }

        return in_array( $header, $allowed, true ) ? $header : '';
    }

    private static function valid_ip( string $ip ): string {
        $ip = trim( $ip );
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
    }

    /**
     * Record one hit against a rolling-window counter.
     *
     * @return bool  True if the caller is still within the limit.
     */
    private static function hit( string $key, int $limit, int $window ): bool {
        $now    = time();
        $window = max( 1, $window );
        $data   = get_site_transient( $key );

        $expired = ! is_array( $data )
            || ! isset( $data['count'], $data['window_start'] )
            || ( $now - (int) $data['window_start'] ) > $window;

        if ( $expired ) {
            set_site_transient( $key, [ 'count' => 1, 'window_start' => $now ], $window );
            return $limit >= 1;
        }

        $data['count'] = (int) $data['count'] + 1;
        $remaining     = $window - ( $now - (int) $data['window_start'] );

        set_site_transient( $key, $data, max( 1, $remaining ) );

        return $data['count'] <= $limit;
    }
}
