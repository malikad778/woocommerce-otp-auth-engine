<?php
defined('ABSPATH') || exit;

/**
 * WCA_Settings - Renders and saves all plugin settings to wp_sitemeta.
 */
class WCA_Settings
{

    private static array $fields = [
        // Email Subject Lines (editable) - template_key pairs each subject with
        // its body field below for the live-preview / test-send JS wiring.
        'wca_subject_registration'    => ['type' => 'text', 'label' => 'Email Subject - Registration',    'group' => 'email', 'default' => 'Verify Your Registration',                       'template_key' => 'registration',    'desc' => 'No OTP variable available for this one (uses a link instead).'],
        'wca_subject_login'           => ['type' => 'text', 'label' => 'Email Subject - Login OTP',       'group' => 'email', 'default' => 'Your Verification code: {{ params.OTP_CODE }}', 'template_key' => 'login',            'desc' => 'Use {{ params.OTP_CODE }} to insert the code into the subject.'],
        'wca_subject_forgot_password' => ['type' => 'text', 'label' => 'Email Subject - Forgot Password', 'group' => 'email', 'default' => 'Password Reset Code: {{ params.OTP_CODE }}',  'template_key' => 'forgot_password', 'desc' => 'Use {{ params.OTP_CODE }} to insert the code into the subject.'],
        'wca_subject_profile_update'  => ['type' => 'text', 'label' => 'Email Subject - Profile Update',  'group' => 'email', 'default' => 'Profile Update Code: {{ params.OTP_CODE }}',   'template_key' => 'profile_update',  'desc' => 'Use {{ params.OTP_CODE }} to insert the code into the subject.'],

        // Email Bodies (HTML) - defaults ship from email-templates/*.html via WCA_Template_Engine.
        'wca_email_body_registration'    => ['type' => 'html', 'label' => 'Email Body - Registration',    'group' => 'email', 'template_key' => 'registration'],
        'wca_email_body_login'           => ['type' => 'html', 'label' => 'Email Body - Login OTP',       'group' => 'email', 'template_key' => 'login'],
        'wca_email_body_forgot_password' => ['type' => 'html', 'label' => 'Email Body - Forgot Password', 'group' => 'email', 'template_key' => 'forgot_password'],
        'wca_email_body_profile_update'  => ['type' => 'html', 'label' => 'Email Body - Profile Update',  'group' => 'email', 'template_key' => 'profile_update'],

        // TextMagic
        'wca_textmagic_username'  => ['type' => 'text', 'label' => 'TextMagic Username', 'group' => 'sms'],
        'wca_textmagic_api_key'   => ['type' => 'text', 'label' => 'TextMagic API Key',  'group' => 'sms'],
        'wca_sms_otp_template'    => ['type' => 'text', 'label' => 'SMS Template (use {OTP})', 'group' => 'sms', 'default' => 'WCA Code: {OTP}. Valid 10 min. Do not share.'],

        // reCAPTCHA & Security Messages
        'wca_recaptcha_site_key'      => ['type' => 'text',     'label' => 'reCAPTCHA v3 Site Key',                    'group' => 'security', 'desc' => 'Optional. Leaving reCAPTCHA unconfigured is supported: the site keeps working and the SMS controls under "OTP & Rate Limiting" become the only abuse defence. Those must then be correctly set - see the notice at the top of this page.'],
        'wca_recaptcha_secret_key'    => ['type' => 'text',     'label' => 'reCAPTCHA v3 Secret Key',                  'group' => 'security'],
        'wca_recaptcha_threshold'     => ['type' => 'text',     'label' => 'reCAPTCHA Score Threshold (0.0-1.0)',       'group' => 'security', 'default' => '0.5'],
        'wca_recaptcha_fail_open'     => ['type' => 'number',   'label' => 'reCAPTCHA Fail Open on Outage (1 = yes, 0 = no)', 'group' => 'security', 'default' => 0, 'desc' => 'Applies only when keys ARE configured and Google cannot be reached. 0 rejects those requests; 1 lets them through. Has no effect when no keys are set - an unconfigured check simply has no verdict, and the SMS limits below take over.'],
        'wca_trusted_proxy_header'    => ['type' => 'text',     'label' => 'Trusted Proxy Header',                      'group' => 'security', 'default' => '', 'desc' => 'Leave blank unless the site sits behind a proxy that overwrites this header (Cloudflare: HTTP_CF_CONNECTING_IP). Blank means rate limits key off REMOTE_ADDR, the only value a client cannot forge. Setting this while NOT behind such a proxy lets anyone bypass every IP rate limit.'],
        'wca_lock_message_template'   => ['type' => 'textarea', 'label' => 'Lock Account Message Template',            'group' => 'security', 'default' => 'Your account has been locked for violating our Terms & Conditions ({REASON}). To lift the suspension, reach out to our support team at {PHONE} or {EMAIL}.', 'desc' => 'Available variables: {REASON}, {PHONE}, {EMAIL}, {TERMS_LINK}'],

        // OTP / Rate Limiting
        'wca_otp_ttl'        => ['type' => 'number', 'label' => 'OTP TTL (seconds)',          'group' => 'limits', 'default' => 600],
        'wca_sms_enabled'    => ['type' => 'number', 'label' => 'SMS Enabled (1 = on, 0 = off)', 'group' => 'limits', 'default' => 1, 'desc' => 'Kill switch. Set to 0 to stop all outbound SMS immediately without deactivating the plugin.'],
        'wca_sms_allowed_countries' => ['type' => 'text', 'label' => 'Allowed Destination Dial Codes', 'group' => 'limits', 'default' => '44', 'desc' => 'Comma-separated, no "+" (e.g. 44,353). SMS to any other country is refused. Blank disables the restriction - do not leave it blank, an open destination list is what makes SMS-pumping fraud profitable.'],
        'wca_sms_rate_limit' => ['type' => 'number', 'label' => 'SMS Rate Limit (requests per IP)',  'group' => 'limits', 'default' => 10, 'desc' => 'Counts only requests that actually send a message - a mistyped password or duplicate email costs nothing. Keep some headroom: mobile networks put many customers behind one shared IP.'],
        'wca_sms_rate_window'=> ['type' => 'number', 'label' => 'SMS Rate Window (seconds)',  'group' => 'limits', 'default' => 900],
        'wca_sms_phone_limit'  => ['type' => 'number', 'label' => 'Max SMS per Phone Number',   'group' => 'limits', 'default' => 3, 'desc' => 'Per destination number, per window below. Independent of IP, so it still holds when the attacker rotates addresses.'],
        'wca_sms_phone_window' => ['type' => 'number', 'label' => 'Phone Number Window (seconds)', 'group' => 'limits', 'default' => 3600],
        'wca_sms_global_limit' => ['type' => 'number', 'label' => 'Network-wide SMS Cap',        'group' => 'limits', 'default' => 100, 'desc' => 'Circuit breaker: total SMS across the whole network per window below. 0 disables it. Set this near your real peak hourly signup volume - it is the hard ceiling on what a single incident can cost.'],
        'wca_sms_global_window'=> ['type' => 'number', 'label' => 'Network-wide Window (seconds)', 'group' => 'limits', 'default' => 3600],
        'wca_email_recipient_limit'  => ['type' => 'number', 'label' => 'Max Emails per Recipient', 'group' => 'limits', 'default' => 5, 'desc' => 'Per address, per window below. Stops a single customer being email-bombed via the OTP/resend endpoints.'],
        'wca_email_recipient_window' => ['type' => 'number', 'label' => 'Email Recipient Window (seconds)', 'group' => 'limits', 'default' => 3600],
        'wca_email_global_limit'     => ['type' => 'number', 'label' => 'Network-wide Email Cap', 'group' => 'limits', 'default' => 200, 'desc' => 'Total plugin emails across the network per window below. 0 disables it. Email costs nothing per message, so the risk is a throttled or blacklisted sending domain - which silently breaks WooCommerce order emails.'],
        'wca_email_global_window'    => ['type' => 'number', 'label' => 'Network-wide Email Window (seconds)', 'group' => 'limits', 'default' => 3600],

        // Global Announcement Banner - shown to every visitor on the Sign In
        // screen, before they identify themselves. Distinct from the per-customer
        // Login Notify panel below it on the same tab.
        'wca_global_announcement' => ['type' => 'raw_html', 'label' => 'Banner Message', 'group' => 'announcement', 'desc' => 'Shown to every visitor on the Sign In screen, right under "Return to Shop" - before they identify themselves. Leave blank to hide it. HTML allowed.'],
    ];

    private static array $groups = [
        'email' => 'Email',
        'sms' => 'SMS (TextMagic)',
        'security' => 'Security (reCAPTCHA)',
        'limits' => 'OTP & Rate Limiting',
        'announcement' => 'Global Announcement Banner',
    ];

    /** Which settings groups render under which top-level admin tab. */
    private static array $tab_groups = [
        'general' => ['sms', 'security', 'limits'],
        'emails'  => ['email'],
        'login_notify' => ['announcement'],
    ];

    // --- SMS abuse-control status banner ----------------------------------

    /**
     * Shown at the top of the settings page. Running without reCAPTCHA is a
     * supported choice, but it makes the SMS limits load-bearing, so the
     * admin should be able to see at a glance what is actually holding the
     * line - rather than discovering it from a gateway invoice.
     */
    public static function render_protection_notice(): void
    {
        $recaptcha = WCA_Recaptcha::is_configured();
        $countries = WCA_Constants::sms_allowed_countries();
        $global    = WCA_Constants::sms_global_limit();

        if (!WCA_Constants::sms_enabled()) {
            echo '<div class="notice notice-warning"><p><strong>' .
                esc_html__('SMS dispatch is switched off.', 'wca-auth-engine') . '</strong> ' .
                esc_html__('No verification codes are being sent. Set "SMS Enabled" to 1 to resume.', 'wca-auth-engine') .
                '</p></div>';
            return;
        }

        if ($recaptcha) {
            return; // Bot detection present; the banner would just be noise.
        }

        if (empty($countries)) {
            echo '<div class="notice notice-error"><p><strong>' .
                esc_html__('SMS is blocked: no abuse controls are configured.', 'wca-auth-engine') . '</strong> ' .
                esc_html__('reCAPTCHA has no keys and the destination allowlist is empty, which is the exact combination that allows SMS-pumping fraud. Set "Allowed Destination Dial Codes" to 44 (or your real list), or configure reCAPTCHA keys.', 'wca-auth-engine') .
                '</p></div>';
            return;
        }

        $effective_global = $global > 0 ? $global : 100;

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s</p><p>%s</p></div>',
            esc_html__('Running without reCAPTCHA.', 'wca-auth-engine'),
            esc_html__('This is supported, but the SMS limits below are now the only thing preventing SMS-pumping fraud. Verify they are right for your traffic:', 'wca-auth-engine'),
            sprintf(
                /* translators: 1: dial codes, 2: per-phone limit, 3: per-IP limit, 4: network-wide cap */
                esc_html__('Destinations limited to %1$s - %2$d codes per number per hour - %3$d per IP per window - hard ceiling of %4$d SMS per hour network-wide.', 'wca-auth-engine'),
                '<code>+' . esc_html(implode(', +', $countries)) . '</code>',
                (int) WCA_Constants::sms_phone_limit(),
                (int) WCA_Constants::sms_rate_limit(),
                (int) $effective_global
            )
        );
    }

    // --- Handle save (POST) -----------------------------------------------

    public static function handle_save(): void
    {
        if (!isset($_POST['wca_settings_nonce_field']) || !wp_verify_nonce($_POST['wca_settings_nonce_field'], 'wca_settings_nonce')) {
            return;
        }

        if (!current_user_can('manage_network_options')) {
            return;
        }

        foreach (self::$fields as $key => $config) {
            if (!isset($_POST[$key]))
                continue;

            $value = wp_unslash($_POST[$key]);

            if ($config['type'] === 'number') {
                $value = absint($value);
            } elseif ($key === 'wca_recaptcha_threshold') {
                $value = (float) $value;
                $value = max(0.0, min(1.0, $value));
            } elseif ($config['type'] === 'html' || $config['type'] === 'raw_html') {
                // Email bodies need <style>/<head> blocks that wp_kses_post() strips.
                // This page is gated by manage_network_options (super admins hold
                // unfiltered_html on multisite), so store raw for them.
                $value = current_user_can('unfiltered_html') ? $value : wp_kses_post($value);
            } elseif ($config['type'] === 'textarea') {
                $value = sanitize_textarea_field($value);
            } else {
                $value = sanitize_text_field($value);
            }

            // Don't overwrite password fields with blank values.
            if ($config['type'] === 'password' && empty($value)) {
                continue;
            }

            update_site_option($key, $value);
        }

        WCA_Constants::flush_cache();

        add_settings_error('wca_settings', 'saved', __('Settings saved.', 'wca-auth-engine'), 'updated');
    }

    // --- Render form ------------------------------------------------------

    public static function render_form(string $tab = 'general'): void
    {
        $allowed_groups = self::$tab_groups[$tab] ?? self::$tab_groups['general'];
        ?>
        <form method="post" class="wca-settings-form">
            <?php wp_nonce_field( 'wca_settings_nonce', 'wca_settings_nonce_field' ); ?>

            <?php foreach ( self::$groups as $group_key => $group_label ) :
                if ( ! in_array( $group_key, $allowed_groups, true ) ) {
                    continue;
                }
                ?>
                <h2 class="title"><?php echo esc_html($group_label); ?></h2>

                <?php if ($group_key === 'sms' && get_site_option('wca_textmagic_username') && get_site_option('wca_textmagic_api_key')):
                    $balance = WCA_TextMagic_Client::get_balance();
                    if (is_wp_error($balance)): ?>
                        <div class="notice notice-error inline">
                            <p><strong>TextMagic Error:</strong> <?php echo esc_html($balance->get_error_message()); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="notice notice-info inline">
                            <p><strong>Current TextMagic Balance:</strong> <?php echo number_format($balance, 2); ?></p>
                        </div>
                        <?php if ($balance < 10): ?>
                            <div class="notice notice-warning inline">
                                <p><strong>Warning:</strong> Balance is below 10. Please ensure auto-reload is enabled in
                                    TextMagic to prevent SMS outages.</p>
                            </div>
                        <?php endif; ?>
                    <?php endif;
                endif; ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <?php foreach (self::$fields as $key => $config):
                            if ($config['group'] !== $group_key)
                                continue;
                            $value = get_site_option($key, $config['default'] ?? '');
                            $display = $config['type'] === 'password' ? '' : esc_attr($value);
                            ?>
                            <tr>
                                <th scope="row">
                                    <label for="<?php echo esc_attr($key); ?>">
                                        <?php echo esc_html($config['label']); ?>
                                    </label>
                                </th>
                                <td>
                                    <?php if ($config['type'] === 'html'):
                                        $template_key = $config['template_key'] ?? '';
                                        $body_value = $value !== '' ? (string) $value : WCA_Template_Engine::default_body($template_key);
                                        self::render_email_body_field($key, $template_key, $body_value);
                                    elseif ($config['type'] === 'raw_html'): ?>
                                        <textarea id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" class="large-text code" rows="8" style="font-family:monospace;font-size:12px;"><?php echo esc_textarea($display); ?></textarea>
                                        <?php if (!empty($config['desc'])): ?>
                                            <p class="description"><?php echo esc_html($config['desc']); ?></p>
                                        <?php endif; ?>
                                    <?php elseif ($config['type'] === 'textarea'): ?>
                                        <textarea id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" class="large-text" rows="4"><?php echo esc_textarea($display); ?></textarea>
                                        <?php if (!empty($config['desc'])): ?>
                                            <p class="description"><?php echo esc_html($config['desc']); ?></p>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <input type="<?php echo esc_attr($config['type']); ?>" id="<?php echo esc_attr($key); ?>"
                                            name="<?php echo esc_attr($key); ?>" value="<?php echo $display; ?>" class="regular-text"
                                            <?php if (!empty($config['template_key'])): ?>
                                                data-wca-subject-key="<?php echo esc_attr($config['template_key']); ?>"
                                            <?php endif; ?>
                                            <?php if ($config['type'] === 'password'): ?>
                                                placeholder="<?php esc_attr_e('(leave blank to keep current value)', 'wca-auth-engine'); ?>"
                                                autocomplete="new-password" <?php endif; ?>                     <?php if ($config['type'] === 'number'): ?> min="0"
                                            <?php endif; ?>>
                                        <?php if (!empty($config['desc'])): ?>
                                            <p class="description"><?php echo esc_html($config['desc']); ?></p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!empty($value) && $config['type'] === 'password'): ?>
                                        <span class="description" style="color:#0a7a0a;">
                                            <?php esc_html_e('Key is configured', 'wca-auth-engine'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>

            <p class="submit">
                <input type="submit" name="wca_settings_save" class="button button-primary"
                    value="<?php esc_attr_e('Save Settings', 'wca-auth-engine'); ?>">
            </p>
        </form>
        <?php
    }

    // --- Email body field: textarea + hint + live preview + test-send -----

    private static function render_email_body_field(string $key, string $template_key, string $body_value): void
    {
        ?>
        <div class="wca-template-selector" style="display:flex; gap: 20px; align-items: flex-start; flex-wrap: wrap;">
            <div style="flex: 1 1 320px; min-width: 280px;">
                <textarea id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" class="large-text code"
                    rows="18" style="font-family:monospace; font-size:12px;"
                    data-wca-body-editor data-template-key="<?php echo esc_attr($template_key); ?>"
                ><?php echo esc_textarea($body_value); ?></textarea>

                <p class="description">
                    <?php esc_html_e('Available placeholders:', 'wca-auth-engine'); ?>
                    <?php foreach (WCA_Template_Engine::available_params($template_key) as $param): ?>
                        <code>{{ params.<?php echo esc_html($param); ?> }}</code>
                    <?php endforeach; ?>
                </p>

                <div class="wca-test-send-row" data-template-key="<?php echo esc_attr($template_key); ?>"
                     style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="email" class="regular-text wca-test-send-email" style="width:220px;"
                           placeholder="<?php esc_attr_e('Send test to', 'wca-auth-engine'); ?>">
                    <button type="button" class="button wca-test-send-btn"><?php esc_html_e('Send Test Email', 'wca-auth-engine'); ?></button>
                    <span class="wca-test-send-result" style="font-size:12px;"></span>
                </div>
            </div>
            <div style="flex: 1 1 280px; min-width: 260px; max-width: 420px; border: 1px solid #ddd; background: #fff; min-height: 200px; padding: 4px; border-radius: 4px;">
                <div style="font-size: 11px; color: #666; margin-bottom: 4px; border-bottom: 1px solid #eee; padding-bottom: 4px;">
                    <?php esc_html_e('Live Preview (sample data):', 'wca-auth-engine'); ?>
                </div>
                <div data-wca-subject-preview style="font-size:12px;font-weight:600;padding:4px 2px;border-bottom:1px dashed #eee;margin-bottom:4px;min-height:16px;"></div>
                <iframe data-wca-preview-frame style="width: 100%; height: 230px; border: none;"></iframe>
            </div>
        </div>
        <?php
    }
}
