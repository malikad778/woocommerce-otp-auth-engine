<?php
defined('ABSPATH') || exit;

/**
 * WCA_Email_Test_Controller - AJAX handler for the "Send Test Email" button
 * on the Email Templates settings tab. Sends the *unsaved* draft subject/body
 * currently in the textarea, rendered with sample data, through the real
 * WCA_Email_Client pipeline (including per-site sender resolution).
 */
class WCA_Email_Test_Controller
{

    private const RATE_LIMIT_MAX = 5;

    public static function init(): void
    {
        add_action('wp_ajax_wca_test_email', [self::class, 'handle']);
    }

    public static function handle(): void
    {
        ob_start();
        check_ajax_referer('wca_test_email');

        if (!current_user_can('manage_network_options')) {
            ob_end_clean();
            wp_send_json_error(['message' => __('Permission denied.', 'wca-auth-engine')], 403);
        }

        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!is_email($email)) {
            ob_end_clean();
            wp_send_json_error(['message' => __('Please enter a valid email address.', 'wca-auth-engine')], 422);
        }

        $template_key = sanitize_key(wp_unslash($_POST['template_key'] ?? ''));
        if (!WCA_Template_Engine::is_valid_key($template_key)) {
            ob_end_clean();
            wp_send_json_error(['message' => __('Unknown email template.', 'wca-auth-engine')], 422);
        }

        if (self::rate_limited()) {
            ob_end_clean();
            wp_send_json_error(['message' => __('Too many test sends. Please wait a minute and try again.', 'wca-auth-engine')], 429);
        }

        $blog_id = null;
        if (isset($_POST['blog_id']) && $_POST['blog_id'] !== '') {
            $blog_id = absint($_POST['blog_id']);
        }

        $subject_draft = trim(wp_unslash($_POST['subject_draft'] ?? ''));
        
        // Handle WAF-bypass base64 encoding
        $raw_body = $_POST['body_draft'] ?? '';
        if (!empty($_POST['is_b64'])) {
            $raw_body = base64_decode($raw_body);
        }
        $body_draft = trim(wp_unslash($raw_body));

        $subject_source = $subject_draft !== '' ? sanitize_text_field($subject_draft) : WCA_Template_Engine::get_subject($template_key);
        $body_source = $body_draft !== ''
            ? (current_user_can('unfiltered_html') ? $body_draft : wp_kses_post($body_draft))
            : WCA_Template_Engine::get_body($template_key);

        $params = WCA_Template_Engine::sample_params($template_key);

        $subject = WCA_Template_Engine::render($subject_source, $params);
        $body = WCA_Template_Engine::render($body_source, $params);

        $result = WCA_Email_Client::send([
            'to' => $email,
            'to_name' => '',
            'template_key' => $template_key,
            'subject' => $subject,
            'body' => $body,
            'blog_id' => $blog_id,
            // Admin-triggered test send: an operator clicking this repeatedly
            // is not the abuse case the recipient limit exists for.
            'skip_rate_limit' => true,
        ]);

        ob_end_clean();
        if (is_wp_error($result)) {
            $error_msg = $result->get_error_message();
            global $phpmailer;
            if (isset($phpmailer) && is_object($phpmailer) && !empty($phpmailer->ErrorInfo)) {
                $error_msg .= ' (PHPMailer: ' . $phpmailer->ErrorInfo . ')';
            }
            wp_send_json_error(['message' => $error_msg], 500);
        }

        wp_send_json_success([
            'message' => sprintf(__('Test email sent to %s.', 'wca-auth-engine'), $email),
        ]);
    }

    // --- Rate limit: max 5 test sends / minute / admin ---------------------

    private static function rate_limited(): bool
    {
        $key = 'wca_test_email_rl_' . get_current_user_id();
        $count = (int) get_transient($key);

        if ($count >= self::RATE_LIMIT_MAX) {
            return true;
        }

        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return false;
    }
}
