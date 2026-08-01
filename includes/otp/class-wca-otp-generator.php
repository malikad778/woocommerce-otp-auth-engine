<?php
defined( 'ABSPATH' ) || exit;

/**
 * WCA_OTP_Generator - Generates cryptographically secure 6-digit OTP codes.
 *
 * Uses random_int() (CSPRNG) - never rand() or mt_rand().
 * All codes are strictly numeric (GSM-7 safe, no Unicode, no symbols).
 */
class WCA_OTP_Generator {

    /**
     * Generate a 6-digit OTP.
     *
     * @return array{
     *   code:   string,   Raw 6-digit code (for dispatch only - never store this).
     *   hash:   string,   password_hash() of the code (safe to store).
     *   expiry: int,      Unix timestamp when code expires.
     * }
     */
    public static function generate(): array {
        $ttl  = WCA_Constants::otp_ttl();
        $code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );

        return [
            'code'   => $code,
            'hash'   => password_hash( $code, PASSWORD_BCRYPT ),
            'expiry' => time() + $ttl,
        ];
    }
}
