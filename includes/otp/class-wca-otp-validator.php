<?php
defined( 'ABSPATH' ) || exit;

/**
 * WCA_OTP_Validator - Validates OTP codes with constant-time comparison and TTL enforcement.
 */
class WCA_OTP_Validator {

    /**
     * Validate a submitted OTP code against the stored hash.
     *
     * @param string $submitted_code  The raw code entered by the user.
     * @param string $stored_hash     The password_hash() value from the transient.
     * @param int    $expiry          Unix timestamp of code expiry.
     *
     * @return true|WP_Error
     */
    public static function validate( string $submitted_code, string $stored_hash, int $expiry ): true|WP_Error {
        // Sanitize: must be exactly 6 digits.
        $code = preg_replace( '/\D/', '', $submitted_code );
        $code = str_pad( $code, 6, '0', STR_PAD_LEFT );

        // TTL check first - fail silently to not reveal whether code was correct.
        if ( time() > $expiry ) {
            return new WP_Error( 'otp_expired', 'The code has expired. Please request a new one.' );
        }

        // Constant-time comparison via password_verify() (prevents timing attacks).
        if ( ! password_verify( $code, $stored_hash ) ) {
            return new WP_Error( 'invalid_otp', 'The code you entered is incorrect.' );
        }

        return true;
    }
}
