<?php
defined('ABSPATH') || exit;

/**
 * WCA_Registration_Validator - Sanitizes and validates all registration inputs.
 * Performs duplicate checks against wp_users and billing_phone usermeta.
 */
class WCA_Registration_Validator
{

    public function validate(array $data): array|WP_Error
    {
        $errors = new WP_Error();

        // -- First name ----------------------------------------------------
        $first_name = sanitize_text_field($data['first_name'] ?? '');
        if (strlen($first_name) < 1 || strlen($first_name) > 50) {
            $errors->add('invalid_first_name', 'Please enter a valid first name (1-50 characters).');
        }

        // -- Last name -----------------------------------------------------
        $last_name = sanitize_text_field($data['last_name'] ?? '');
        if (strlen($last_name) < 1 || strlen($last_name) > 50) {
            $errors->add('invalid_last_name', 'Please enter a valid last name (1-50 characters).');
        }

        // -- Email ---------------------------------------------------------
        $email = WCA_Sanitizer::sanitize_email($data['email'] ?? '');
        if (!$email) {
            $errors->add('invalid_email', 'Please enter a valid email address.');
        } elseif (email_exists($email)) {
            $errors->add('email_exists', 'An account with that email address already exists.');
        }

        // -- Phone ---------------------------------------------------------
        $phone = WCA_Sanitizer::normalize_phone($data['phone'] ?? '');
        if (!$phone) {
            $errors->add('invalid_phone', 'Please enter a valid UK phone number.');
        } elseif (!WCA_Sanitizer::is_allowed_destination($phone)) {
            // Rejected before any OTP is generated, so a signup aimed at a
            // premium-rate range abroad never reaches the SMS gateway.
            WCA_Logger::log('REGISTRATION_COUNTRY_BLOCKED', [
                'masked_phone' => WCA_Logger::mask_phone($phone),
                'ip_hash' => md5(WCA_Rate_Limiter::get_client_ip() . AUTH_SALT),
            ]);
            $errors->add('phone_country_blocked', 'We can only verify phone numbers from the countries we deliver to. Please enter a valid UK mobile number.');
        } elseif ($this->phone_exists($phone)) {
            $errors->add('phone_exists', 'An account with that phone number already exists.');
        }

        // -- Password ------------------------------------------------------
        $password = $data['password'] ?? '';
        $pw_check = $this->validate_password($password);
        if (is_wp_error($pw_check)) {
            foreach ($pw_check->get_error_codes() as $code) {
                $errors->add($code, $pw_check->get_error_message($code));
            }
        }

        if ($errors->has_errors()) {
            // Return the first error only to avoid overwhelming the user.
            $first_code = $errors->get_error_codes()[0];
            return new WP_Error($first_code, $errors->get_error_message($first_code));
        }

        return [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'password' => $password,
        ];
    }

    // --- Password complexity check ----------------------------------------

    private function validate_password(string $password): bool|WP_Error
    {
        $errors = new WP_Error();

        if (strlen($password) < 8) {
            $errors->add('password_too_short', 'Password must be at least 8 characters long.');
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors->add('password_no_upper', 'Password must include at least one uppercase letter.');
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors->add('password_no_digit', 'Password must include at least one number.');
        }

        return $errors->has_errors() ? $errors : true;
    }

    // --- Check phone uniqueness across network ----------------------------

    private function phone_exists(string $phone): bool
    {
        global $wpdb;

        // Try exact match first.
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(um.user_id) FROM {$wpdb->usermeta} um
             INNER JOIN {$wpdb->users} u ON um.user_id = u.ID
             WHERE um.meta_key = 'billing_phone' AND um.meta_value = %s",
            $phone
        ));

        // Fallback: Check if the last 10 digits match an existing number.
        if ($count === 0) {
            $digits = preg_replace('/[^\d]/', '', $phone);
            if (strlen($digits) >= 10) {
                $last_10 = substr($digits, -10);
                $count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(um.user_id) FROM {$wpdb->usermeta} um
                     INNER JOIN {$wpdb->users} u ON um.user_id = u.ID
                     WHERE um.meta_key = 'billing_phone' AND um.meta_value LIKE %s",
                    '%' . $last_10
                ));
            }
        }

        return $count > 0;
    }
}
