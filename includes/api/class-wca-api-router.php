<?php
defined('ABSPATH') || exit;

/**
 * WCA_API_Router - Registers all REST routes under custom-auth/v1.
 */
class WCA_API_Router
{

    public static function register_routes(): void
    {
        // -- Registration -------------------------------------------------
        register_rest_route(WCA_NAMESPACE, '/register/initiate', [
            'methods' => 'POST',
            'callback' => ['WCA_Endpoint_Register', 'initiate'],
            'permission_callback' => '__return_true',
            'args' => self::registration_args(),
        ]);

        register_rest_route(WCA_NAMESPACE, '/register/verify-email', [
            // Accept GET (email link click) and POST (JS re-submission).
            'methods' => 'GET, POST',
            'callback' => ['WCA_Endpoint_Register', 'verify_email'],
            'permission_callback' => '__return_true',
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'token' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route(WCA_NAMESPACE, '/register/verify-sms', [
            'methods' => 'POST',
            'callback' => ['WCA_Endpoint_Register', 'verify_sms'],
            'permission_callback' => '__return_true',
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'otp_code' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route(WCA_NAMESPACE, '/register/complete', [
            'methods' => 'POST',
            'callback' => ['WCA_Endpoint_Register', 'complete'],
            'permission_callback' => '__return_true',
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // -- Login --------------------------------------------------------
        register_rest_route(WCA_NAMESPACE, '/login/check-identifier', [
            'methods' => 'POST',
            'callback' => ['WCA_Endpoint_Login', 'check_identifier'],
            'permission_callback' => '__return_true',
            'args' => [
                'identifier' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route(WCA_NAMESPACE, '/login/send-otp', [
            'methods' => 'POST',
            'callback' => ['WCA_Endpoint_Login', 'send_otp'],
            'permission_callback' => '__return_true',
            'args' => [
                'identifier' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'recaptcha_token' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'channel' => ['required' => false, 'type' => 'string', 'default' => 'sms', 'enum' => ['sms', 'email']],
            ],
        ]);

        register_rest_route(WCA_NAMESPACE, '/login/authenticate', [
            'methods' => 'POST',
            'callback' => ['WCA_Endpoint_Login', 'authenticate'],
            'permission_callback' => '__return_true',
            'args' => [
                'identifier' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'password' => ['required' => false, 'type' => 'string'],
                'otp_code' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'session_token' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                // Fix #8: Optional redirect_to; validated server-side before use.
                'redirect_to' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // -- OTP ----------------------------------------------------------
        register_rest_route(WCA_NAMESPACE, '/otp/resend', [
            'methods' => 'POST',
            'callback' => ['WCA_Endpoint_OTP', 'resend'],
            'permission_callback' => '__return_true',
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'channel' => ['required' => false, 'type' => 'string', 'default' => 'sms', 'enum' => ['sms', 'email']],
                'recaptcha_token' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route(WCA_NAMESPACE, '/otp/status', [
            'methods' => 'POST',
            'callback' => ['WCA_Endpoint_OTP', 'status'],
            'permission_callback' => '__return_true',
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // -- Profile ------------------------------------------------------
        register_rest_route(WCA_NAMESPACE, '/profile/update-initiate', [
            'methods' => 'POST',
            'callback' => ['WCA_Endpoint_Profile', 'update_initiate'],
            'permission_callback' => ['WCA_Endpoint_Profile', 'require_auth'],
            'args' => [
                'email' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_email'],
                'phone' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'recaptcha_token' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route(WCA_NAMESPACE, '/profile/verify-update', [
            'methods' => 'POST',
            'callback' => ['WCA_Endpoint_Profile', 'verify_update'],
            'permission_callback' => ['WCA_Endpoint_Profile', 'require_auth'],
            'args' => [
                'channel' => ['required' => true, 'type' => 'string', 'enum' => ['email', 'sms']],
                'otp_code' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // -- Password -----------------------------------------------------
        register_rest_route(WCA_NAMESPACE, '/password/forgot', [
            'methods' => 'POST',
            'callback' => ['WCA_Endpoint_Password', 'initiate_reset'],
            'permission_callback' => '__return_true',
            'args' => [
                'identifier' => ['required' => true, 'type' => 'string'],
                'recaptcha_token' => ['required' => false, 'type' => 'string'],
            ],
        ]);

        // /password/verify-otp
        register_rest_route(WCA_NAMESPACE, '/password/verify-otp', [
            'methods' => 'POST',
            'callback' => ['WCA_Endpoint_Password', 'verify_reset_otp'],
            'permission_callback' => '__return_true',
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string'],
                'otp_code' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        // /password/reset
        register_rest_route(WCA_NAMESPACE, '/password/reset', [
            'methods' => 'POST',
            'callback' => ['WCA_Endpoint_Password', 'complete_reset'],
            'permission_callback' => '__return_true',
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string'],
                'password' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        // --- Add Phone (legacy accounts) ----------------------------------
        register_rest_route(WCA_NAMESPACE, '/profile/add-phone/send-otp', [
            'methods' => 'POST',
            'callback' => ['WCA_Endpoint_Add_Phone', 'send_otp'],
            'permission_callback' => 'is_user_logged_in',
            'args' => [
                'phone' => ['required' => true, 'type' => 'string'],
                'recaptcha_token' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route(WCA_NAMESPACE, '/profile/add-phone/verify-otp', [
            'methods' => 'POST',
            'callback' => ['WCA_Endpoint_Add_Phone', 'verify_otp'],
            'permission_callback' => 'is_user_logged_in',
            'args' => [
                'phone' => ['required' => true, 'type' => 'string'],
                'otp_code' => ['required' => true, 'type' => 'string'],
                'session_token' => ['required' => true, 'type' => 'string'],
            ],
        ]);
    }

    // --- Shared arg schema for /register/initiate -------------------------

    private static function registration_args(): array
    {
        return [
            'first_name' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'last_name' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'email' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_email', 'validate_callback' => fn($v) => is_email($v)],
            'phone' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'password' => ['required' => true, 'type' => 'string'],
            'recaptcha_token' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
        ];
    }

    // --- Shared JSON response helper -------------------------------------

    public static function success(array $data = [], int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response(array_merge(['success' => true], $data), $status);
    }

    public static function error(string $code, string $message, int $status = 400): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => false,
            'code' => $code,
            'message' => $message,
        ], $status);
    }
}
