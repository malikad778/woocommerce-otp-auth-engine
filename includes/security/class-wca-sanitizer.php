<?php
defined('ABSPATH') || exit;

/**
 * WCA_Sanitizer - Centralised input sanitization and phone normalization.
 *
 * Phone normalization targets UK numbers (E.164 +44 prefix).
 * Operators in other regions can override via the 'wca_default_country_code' filter.
 */
class WCA_Sanitizer
{

    /**
     * Sanitize an email address. Returns false if invalid.
     */
    public static function sanitize_email(string $email): string|false
    {
        $cleaned = sanitize_email($email);
        return is_email($cleaned) ? $cleaned : false;
    }

    /**
     * Sanitize and normalize a phone number to E.164.
     *
     * Strips non-numeric characters, validates length, and applies the
     * default country code prefix if no international prefix is detected.
     *
     * @param string $phone  Raw phone input.
     *
     * @return string|false  E.164 formatted phone (e.g. +447911123456) or false if invalid.
     */
    public static function normalize_phone(string $phone): string|false
    {
        if (empty($phone)) {
            return false;
        }

        // Strip everything except digits and leading +.
        $cleaned = preg_replace('/[^\d+]/', '', $phone);

        // Already has a + international prefix.
        if (str_starts_with($cleaned, '+')) {
            $digits = substr($cleaned, 1);
            if (!preg_match('/^\d{8,15}$/', $digits)) {
                return false;
            }
            return '+' . $digits;
        }

        // Strip leading zeros (UK national format: 07911123456  7911123456).
        $digits = ltrim($cleaned, '0');

        // If 10 digits remain, it's a UK mobile without the leading 0.
        // UK mobile: 7xxx xxx xxx (10 digits after stripping leading 0).
        $default_code = apply_filters('wca_default_country_code', '44');

        if (preg_match('/^\d{10}$/', $digits)) {
            return '+' . $default_code . $digits;
        }

        // If already has the country code digits (e.g. 447911123456).
        if (preg_match('/^44\d{10}$/', $digits)) {
            return '+' . $digits;
        }

        // 11 digits with leading 0 already stripped, starting with 7 (UK mobile).
        if (preg_match('/^7\d{9}$/', $digits)) {
            return '+' . $default_code . $digits;
        }

        // Fallback for international numbers already full-length.
        if (preg_match('/^\d{8,15}$/', $digits)) {
            return '+' . $digits;
        }

        return false;
    }

    /**
     * Is this E.164 number in a country we are willing to send SMS to?
     *
     * An unrestricted destination list is what makes SMS pumping profitable:
     * the attacker owns a range in a high-termination-rate country, points a
     * signup form at it, and collects a share of every message. Restricting
     * destinations to the countries the business actually serves removes the
     * payout even if every other control fails.
     *
     * @param string $phone  E.164 formatted phone (e.g. +447911123456).
     */
    public static function is_allowed_destination(string $phone): bool
    {
        $allowed = WCA_Constants::sms_allowed_countries();

        if (empty($allowed)) {
            return true; // Operator has explicitly disabled the restriction.
        }

        $digits = preg_replace('/\D/', '', $phone);

        if ($digits === '') {
            return false;
        }

        foreach ($allowed as $code) {
            if (str_starts_with($digits, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize an identifier (email or phone). Returns the cleaned string or empty.
     */
    public static function sanitize_identifier(string $raw): string
    {
        $cleaned = sanitize_text_field($raw);

        // If it looks like an email, validate it.
        if (str_contains($cleaned, '@')) {
            return is_email($cleaned) ? sanitize_email($cleaned) : '';
        }

        // If it contains alphabetic characters, treat it as a potential username.
        if (preg_match('/[a-zA-Z]/', $cleaned)) {
            return sanitize_user($cleaned);
        }

        // Otherwise, assume it's a phone number and strip invalid chars.
        return preg_replace('/[^\d+\s\(\)\-]/', '', $cleaned);
    }

    /**
     * Strip all HTML and run sanitize_text_field.
     */
    public static function sanitize_text(string $input): string
    {
        return sanitize_text_field(wp_strip_all_tags($input));
    }

    /**
     * Validate password meets minimum complexity.
     *
     * @return bool
     */
    public static function validate_password_complexity(string $password): bool
    {
        return strlen($password) >= 8
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[0-9]/', $password);
    }
}
