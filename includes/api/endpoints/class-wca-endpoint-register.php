<?php
defined('ABSPATH') || exit;

/**
 * WCA_Endpoint_Register - Handles /register/* REST routes.
 */
class WCA_Endpoint_Register
{

    // --- POST /register/initiate ------------------------------------------

    public static function initiate(WP_REST_Request $request): WP_REST_Response
    {
        $ip = WCA_Rate_Limiter::get_client_ip();

        // 1. reCAPTCHA validation.
        $recaptcha_result = WCA_Recaptcha::verify($request->get_param('recaptcha_token'));
        if (is_wp_error($recaptcha_result)) {
            WCA_Logger::log('RECAPTCHA_FAILED', [
                'step' => 'register_initiate',
                'ip_hash' => md5($ip . AUTH_SALT),
                'reason' => $recaptcha_result->get_error_message()
            ]);
            return WCA_API_Router::error('recaptcha_failed', 'Security check failed. Please refresh and try again.', 403);
        }

        // 2. Sanitize + validate inputs. The IP rate limit lives inside the
        //    pipeline, after validation and only when an SMS is genuinely
        //    about to be dispatched - see WCA_Registration_Pipeline::initiate().
        //    Charging quota here instead meant a mistyped password or a
        //    duplicate email consumed a slot without sending anything, so
        //    three typos locked a customer out for 15 minutes having never
        //    received a message.
        $data = [
            'first_name' => $request->get_param('first_name'),
            'last_name' => $request->get_param('last_name'),
            'email' => $request->get_param('email'),
            'phone' => $request->get_param('phone'),
            'password' => $request->get_param('password'),
            'session_token' => $request->get_param('session_token'),
            'return_url' => $request->get_param('return_url'),
        ];

        $result = WCA_Registration_Pipeline::initiate($data);

        if (is_wp_error($result)) {
            // Rate limiting is a 429, not a validation failure.
            $status = $result->get_error_code() === 'rate_limited' ? 429 : 422;

            return WCA_API_Router::error(
                $result->get_error_code(),
                $result->get_error_message(),
                $status
            );
        }

        return WCA_API_Router::success($result, 201);
    }

    // --- POST/GET /register/verify-email ---------------------------------

    public static function verify_email(WP_REST_Request $request): WP_REST_Response
    {
        // Accept both GET (email link click) and POST (JS resend confirm).
        $session_token = $request->get_param('session_token');
        $token = $request->get_param('token');

        $result = WCA_Registration_Pipeline::verify_email($session_token, $token);

        if (is_wp_error($result)) {
            // If this was a GET (browser link click), redirect with error flag.
            if ($request->get_method() === 'GET') {
                wp_safe_redirect(add_query_arg([
                    'wca_modal' => 'register',
                    'wca_error' => rawurlencode($result->get_error_message()),
                ], wc_get_page_permalink('myaccount')));
                exit;
            }
            return WCA_API_Router::error($result->get_error_code(), $result->get_error_message(), 422);
        }

        // Auto-complete: both verified.
        if (!empty($result['both_verified'])) {
            $complete = self::complete_registration($session_token);

            // On GET (link click): redirect the browser to my-account on success.
            if ($request->get_method() === 'GET') {
                wp_safe_redirect(add_query_arg('wca_verified', '1', wc_get_page_permalink('myaccount')));
                exit;
            }

            return $complete;
        }

        // Email verified but still waiting for SMS.
        if ($request->get_method() === 'GET') {
            wp_safe_redirect(add_query_arg([
                'wca_modal' => 'register',
                'wca_email_verified' => '1',
                'wca_session' => rawurlencode($session_token),
            ], wc_get_page_permalink('myaccount')));
            exit;
        }

        return WCA_API_Router::success(['email_verified' => true, 'message' => 'Email verified. Awaiting SMS verification.']);
    }

    // --- POST /register/verify-sms ---------------------------------------

    public static function verify_sms(WP_REST_Request $request): WP_REST_Response
    {
        $session_token = $request->get_param('session_token');
        $otp_code = $request->get_param('otp_code');

        $result = WCA_Registration_Pipeline::verify_sms($session_token, $otp_code);

        if (is_wp_error($result)) {
            return WCA_API_Router::error($result->get_error_code(), $result->get_error_message(), 422);
        }

        // Auto-complete check.
        if (!empty($result['both_verified'])) {
            return self::complete_registration($session_token);
        }

        return WCA_API_Router::success(['sms_verified' => true, 'message' => 'SMS verified. Awaiting email verification.']);
    }

    // --- POST /register/complete (also called internally) -----------------

    public static function complete(WP_REST_Request $request): WP_REST_Response
    {
        $session_token = $request->get_param('session_token');
        return self::complete_registration($session_token);
    }

    // --- Internal helper --------------------------------------------------

    private static function complete_registration(string $session_token): WP_REST_Response
    {
        $result = WCA_Registration_Pipeline::complete($session_token);

        if (is_wp_error($result)) {
            return WCA_API_Router::error($result->get_error_code(), $result->get_error_message(), 422);
        }

        return WCA_API_Router::success([
            'user_created' => true,
            'redirect' => wc_get_page_permalink('myaccount'),
            'message' => 'Account created successfully.',
        ], 201);
    }
}
