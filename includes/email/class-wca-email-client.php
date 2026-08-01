<?php
defined('ABSPATH') || exit;

/**
 * WCA_Email_Client - Native wp_mail() transactional email sender with
 * dynamic per-site sender routing. Replaces the old Brevo API client.
 *
 * Delivery relies on the existing SMTP plugin already authenticated for
 * outbound mail; this class only decides From/Subject/Body and calls
 * wp_mail().
 */
class WCA_Email_Client
{


    /**
     * @param array $args {
     *   to:            string  Recipient email (required)
     *   to_name:       string  Recipient display name
     *   template_key:  string  'registration'|'login'|'forgot_password'|'profile_update'
     *   params:        array   Placeholder values (FIRST_NAME, OTP_CODE, ...) -
     *                          used to render subject/body when 'subject'/'body'
     *                          are not supplied directly.
     *   subject:       string  Optional pre-rendered subject (bypasses template lookup;
     *                          used by the admin test-send for unsaved drafts).
     *   body:          string  Optional pre-rendered body (same as above).
     *   blog_id:       ?int    Optional explicit site context (defaults to current).
     * }
     */
    public static function send(array $args): true|WP_Error
    {
        $to = trim((string) ($args['to'] ?? ''));
        $to_name = (string) ($args['to_name'] ?? '');
        $template_key = (string) ($args['template_key'] ?? '');
        $blog_id = isset($args['blog_id']) && $args['blog_id'] !== null ? (int) $args['blog_id'] : null;

        if (empty($to) || !is_email($to)) {
            return new WP_Error('wca_invalid_recipient', 'Invalid recipient email address.');
        }

        // Abuse gate, mirroring WCA_TextMagic_Client::send_sms(). Enforced
        // here so every dispatch path is covered by construction rather than
        // by each endpoint remembering to check. Admin test-sends pass
        // skip_rate_limit, since an operator clicking "send test" repeatedly
        // is not the threat model.
        if (empty($args['skip_rate_limit'])) {
            $guard = WCA_Rate_Limiter::guard_email($to);
            if (is_wp_error($guard)) {
                return $guard;
            }
        }

        if (isset($args['subject']) && isset($args['body'])) {
            // Pre-rendered content supplied directly (e.g. admin test-send with unsaved drafts).
            $subject = (string) $args['subject'];
            $body = (string) $args['body'];
        } else {
            $params = is_array($args['params'] ?? null) ? $args['params'] : [];
            $subject = WCA_Template_Engine::render(WCA_Template_Engine::get_subject($template_key), $params);
            $body = WCA_Template_Engine::render(WCA_Template_Engine::get_body($template_key), $params);
        }

        $sender = self::resolve_sender($blog_id);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            sprintf('From: %s <%s>', self::sanitize_header_name($sender['name']), $sender['email']),
        ];

        // Always switch to the target blog before calling wp_mail() so that
        // SMTP plugins (Brevo, WP Mail SMTP, PostSMTP, etc.) load the correct
        // per-site credentials. Without this, sending from network-admin context
        // causes SMTP plugins to fall back to unconfigured PHP mail and silently
        // drop the email even though wp_mail() returns true.
        $switched = false;
        if ($blog_id !== null && is_multisite() && get_blog_details($blog_id)) {
            // Switch whenever we're not already in the exact target site context
            // (covers network-admin context where get_current_blog_id() == main site
            // but SMTP plugin settings are NOT loaded for that site).
            if ($blog_id !== get_current_blog_id() || is_network_admin()) {
                switch_to_blog($blog_id);
                $switched = true;
            }
        }

        $sent = wp_mail($to, $subject, $body, $headers);

        // Capture blog_id BEFORE restoring so the log reflects where mail was sent from.
        $sent_from_blog = get_current_blog_id();

        if ($switched) {
            restore_current_blog();
        }

        if (!$sent) {
            $message = 'wp_mail() returned false.';

            WCA_Logger::log('EMAIL_SEND_FAILED', [
                'masked_email' => WCA_Logger::mask_email($to),
                'template_key' => $template_key,
                'blog_id' => $sent_from_blog,
                'error' => $message,
            ]);

            return new WP_Error('wca_mail_failed', $message);
        }

        WCA_Logger::log('EMAIL_SENT', [
            'channel' => 'email',
            'masked_email' => WCA_Logger::mask_email($to),
            'template_key' => $template_key,
            'blog_id' => $sent_from_blog,
            'from_header' => $headers[0] ?? '', // e.g. "From: KamagraDeal UK <noreply@kamagradeal.xyz>"
            'subject' => $subject,
        ]);

        return true;
    }

    // --- Sender resolution (dynamic multisite routing) ---------------------

    /**
     * Resolve From name/address from the triggering site's WooCommerce
     * settings. Because each domain's REST/link requests execute in that
     * blog's context, this already resolves to the correct site by default;
     * $blog_id lets an admin test-send preview a *different* site's sender.
     *
     * @return array{name: string, email: string}
     */
    private static function resolve_sender(?int $blog_id = null): array
    {
        $switched = false;
        if ($blog_id !== null && is_multisite() && get_blog_details($blog_id)) {
            // Mirror the same condition used in send() - also switch when in
            // network-admin context even if blog_id matches get_current_blog_id(),
            // because network-admin does not load per-site options.
            if ($blog_id !== get_current_blog_id() || is_network_admin()) {
                switch_to_blog($blog_id);
                $switched = true;
            }
        }

        $default_name = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $wc_name = (string) get_option('woocommerce_email_from_name');
        $wc_email = (string) get_option('woocommerce_email_from_address');
        $admin_email = (string) get_option('admin_email');

        WCA_Logger::log('DEBUG_SENDER_RESOLUTION', [
            'blog_id' => get_current_blog_id(),
            'wc_name' => $wc_name,
            'wc_email' => $wc_email,
            'admin_email' => $admin_email,
        ]);

        $name = $wc_name !== '' ? $wc_name : $default_name;
        $email = $wc_email !== '' ? $wc_email : $admin_email;

        if ($name === '') {
            $name = $default_name;
        }

        if ($email === '' || !is_email($email)) {
            WCA_Logger::log('EMAIL_CONFIG_MISSING', ['blog_id' => get_current_blog_id()]);
            $email = $admin_email;
        }

        if ($switched) {
            restore_current_blog();
        }

        return ['name' => $name, 'email' => $email];
    }

    // --- Header injection guard ---------------------------------------------

    private static function sanitize_header_name(string $name): string
    {
        return trim(str_replace(["\r", "\n", '<', '>'], '', $name));
    }

}
