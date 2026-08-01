<?php
defined('ABSPATH') || exit;

/**
 * WCA_Template_Engine - Owns the mapping between email template keys, their
 * subject/body site options, and the shipped default HTML bodies.
 *
 * Placeholder syntax: {{ params.KEY }} - matches the syntax already saved in
 * the wca_subject_* options and already used in email-templates/*.html.
 */
class WCA_Template_Engine
{

    /**
     * Single source of truth for the 4 transactional templates.
     * subject_option/subject_default mirror WCA_Settings::$fields exactly -
     * both read/write the same site option key, so values stay in sync.
     */
    private const TEMPLATES = [
        'registration' => [
            'label' => 'Registration (Verify Link)',
            'subject_option' => 'wca_subject_registration',
            'subject_default' => 'Verify Your Registration',
            'body_option' => 'wca_email_body_registration',
            'default_file' => 'registration-verify.html',
            'params' => ['FIRST_NAME', 'VERIFY_URL', 'EXPIRY_MINUTES'],
        ],
        'login' => [
            'label' => 'Login OTP',
            'subject_option' => 'wca_subject_login',
            'subject_default' => 'Your Verification code: {{ params.OTP_CODE }}',
            'body_option' => 'wca_email_body_login',
            'default_file' => 'login-otp.html',
            'params' => ['FIRST_NAME', 'OTP_CODE', 'EXPIRY_MINUTES'],
        ],
        'forgot_password' => [
            'label' => 'Forgot Password OTP',
            'subject_option' => 'wca_subject_forgot_password',
            'subject_default' => 'Password Reset Code: {{ params.OTP_CODE }}',
            'body_option' => 'wca_email_body_forgot_password',
            'default_file' => 'password-reset.html',
            'params' => ['FIRST_NAME', 'OTP_CODE', 'EXPIRY_MINUTES'],
        ],
        'profile_update' => [
            'label' => 'Profile Update OTP',
            'subject_option' => 'wca_subject_profile_update',
            'subject_default' => 'Profile Update Code: {{ params.OTP_CODE }}',
            'body_option' => 'wca_email_body_profile_update',
            'default_file' => 'profile-update-verify.html',
            'params' => ['FIRST_NAME', 'OTP_CODE', 'EXPIRY_MINUTES'],
        ],
    ];

    // --- Key registry -----------------------------------------------------

    public static function template_keys(): array
    {
        return array_keys(self::TEMPLATES);
    }

    public static function is_valid_key(string $key): bool
    {
        return isset(self::TEMPLATES[$key]);
    }

    public static function label(string $key): string
    {
        return self::TEMPLATES[$key]['label'] ?? $key;
    }

    public static function subject_option_key(string $key): string
    {
        return self::TEMPLATES[$key]['subject_option'] ?? '';
    }

    public static function body_option_key(string $key): string
    {
        return self::TEMPLATES[$key]['body_option'] ?? '';
    }

    // --- Subject / body resolution ----------------------------------------

    /** Saved (or default) subject template - NOT yet rendered with params. */
    public static function get_subject(string $key): string
    {
        if (!self::is_valid_key($key)) {
            return '';
        }
        $tpl = self::TEMPLATES[$key];
        return (string) get_site_option($tpl['subject_option'], $tpl['subject_default']);
    }

    /** Saved (or default) body template - NOT yet rendered with params. */
    public static function get_body(string $key): string
    {
        if (!self::is_valid_key($key)) {
            return '';
        }
        $saved = (string) get_site_option(self::TEMPLATES[$key]['body_option'], '');
        return $saved !== '' ? $saved : self::default_body($key);
    }

    /** The shipped default HTML body for a template, read fresh from disk. */
    public static function default_body(string $key): string
    {
        if (!self::is_valid_key($key)) {
            return '';
        }
        $file = WCA_DIR . 'email-templates/' . self::TEMPLATES[$key]['default_file'];
        if (!file_exists($file)) {
            return '';
        }
        $html = (string) file_get_contents($file);
        return self::strip_leading_comment($html);
    }

    // --- Placeholder rendering ---------------------------------------------

    /**
     * Substitute {{ params.KEY }} placeholders with values from $params.
     * Unset placeholders resolve to an empty string. VERIFY_URL is escaped
     * as a URL (it lands in an href); everything else is escaped as text.
     */
    public static function render(string $template, array $params): string
    {
        $rendered = (string) preg_replace_callback(
            '/\{\{\s*params\.([A-Z0-9_]+)\s*\}\}/',
            static function (array $matches) use ($params): string {
                $key = $matches[1];
                if (!array_key_exists($key, $params) || $params[$key] === null) {
                    return '';
                }
                $value = (string) $params[$key];
                return $key === 'VERIFY_URL' ? esc_url($value) : esc_html($value);
            },
            $template
        );

        // Replace Shopify/Liquid style date tags that crash Brevo's template parser
        $rendered = preg_replace('/\{\{\s*"now"\s*\|\s*date:\s*"%Y"\s*\}\}/', gmdate('Y'), $rendered);

        // Strip any remaining unparsed {{ ... }} tags to guarantee Brevo won't drop the email
        $rendered = preg_replace('/\{\{.*?\}\}/', '', $rendered);

        return $rendered;
    }

    // --- Params metadata (drives hint text + preview/test-send) -----------

    /** The legal placeholder list for a given template key. */
    public static function available_params(string $key): array
    {
        return self::TEMPLATES[$key]['params'] ?? [];
    }

    /** Fixed sample data used for live preview and test-send. */
    public static function sample_params(string $key): array
    {
        return [
            'FIRST_NAME' => 'Robert',
            'OTP_CODE' => '123456',
            'EXPIRY_MINUTES' => 10,
            'VERIFY_URL' => home_url('/my-account/?wca_action=verify_email&session_token=sample&token=sample'),
        ];
    }

    // --- Internal helpers ---------------------------------------------------

    /**
     * Strip a single leading HTML comment (e.g. the "Upload this HTML as a
     * Brevo Transactional Template" author note) so it never reaches an
     * actual sent email or the admin preview. No-op if there is no comment.
     */
    private static function strip_leading_comment(string $html): string
    {
        return (string) preg_replace('/<!--.*?-->\s*/s', '', $html, 1);
    }
}
