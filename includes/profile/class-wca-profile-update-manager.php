<?php
defined('ABSPATH') || exit;

/**
 * WCA_Profile_Update_Manager - Manages profile phone and email display,
 * verifying via OTP instead of native WooCommerce saves.
 */
class WCA_Profile_Update_Manager
{

    /**
     * Hooked to: woocommerce_billing_fields
     * Makes email and phone readonly on the Billing Address edit page.
     */
    public static function make_billing_fields_readonly(array $fields): array
    {
        if (isset($fields['billing_phone'])) {
            $fields['billing_phone']['custom_attributes'] = ['readonly' => 'readonly', 'style' => 'background:#f8fafc;color:#64748b;cursor:default;'];
            $fields['billing_phone']['description'] = esc_html__('To change your phone number, please use the Account Details page.', 'wca-auth-engine');
        }
        if (isset($fields['billing_email'])) {
            $fields['billing_email']['custom_attributes'] = ['readonly' => 'readonly', 'style' => 'background:#f8fafc;color:#64748b;cursor:default;'];
            $fields['billing_email']['description'] = esc_html__('To change your email, please use the Account Details page.', 'wca-auth-engine');
        }
        return $fields;
    }

    /**
     * Hooked to: woocommerce_edit_account_form
     */
    public static function render_phone_field(): void
    {
        $user_id = get_current_user_id();
        $phone = get_user_meta($user_id, 'billing_phone', true);
        $phone_verified = get_user_meta($user_id, 'billing_phone_verified', true);
        $email_verified = get_user_meta($user_id, 'billing_email_verified', true);
        $current_email = get_userdata($user_id)->user_email;

        $phone_badge = '';
        if ($phone) {
            if ($phone_verified) {
                $phone_badge = ' <span class="wca-verified-badge wca-verified-badge--ok">'
                    . '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="13" height="13" style="vertical-align:middle;margin-right:2px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>'
                    . esc_html__('Verified', 'wca-auth-engine') . '</span>';
            } else {
                $phone_badge = ' <span class="wca-verified-badge wca-verified-badge--warn">'
                    . '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="13" height="13" style="vertical-align:middle;margin-right:2px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>'
                    . esc_html__('Unverified', 'wca-auth-engine') . '</span>';
            }
        }

        $email_badge = '';
        if ($email_verified) {
            $email_badge = ' <span class="wca-verified-badge wca-verified-badge--ok">'
                . '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="13" height="13" style="vertical-align:middle;margin-right:2px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>'
                . esc_html__('Verified', 'wca-auth-engine') . '</span>';
        } else {
            $email_badge = ' <span class="wca-verified-badge wca-verified-badge--warn">'
                . '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="13" height="13" style="vertical-align:middle;margin-right:2px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>'
                . esc_html__('Unverified', 'wca-auth-engine') . '</span>';
        }
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="billing_phone"><?php esc_html_e('Mobile Phone', 'wca-auth-engine'); ?>&nbsp;<span
                    class="required">*</span><?php echo $phone_badge; ?>
            </label>
            <input type="tel" class="woocommerce-Input woocommerce-Input--phone input-text" name="billing_phone"
                id="billing_phone" value="<?php echo esc_attr($phone); ?>" readonly="readonly"
                style="background:#f8fafc;color:#64748b;cursor:default;" />
            <span class="description" style="display:block; margin-top:4px; font-size:0.85em; color:#666;">
                <?php esc_html_e('To change your phone number, please click verify.', 'wca-auth-engine'); ?>
            </span>
        <div style="margin-top: 12px;">
            <button type="button" class="wca-verify-action-btn"
                onclick="document.getElementById('wca-modal-profile-update').removeAttribute('hidden'); if(window._wcaProfileUpdateComponent){ window._wcaProfileUpdateComponent.verifyMode='phone'; window._wcaProfileUpdateComponent.newPhone='<?php echo esc_js($phone); ?>'; window._wcaProfileUpdateComponent.newEmail=''; }"><?php echo $phone && !$phone_verified ? esc_html__('Verify mobile number', 'wca-auth-engine') : esc_html__('Update mobile number', 'wca-auth-engine'); ?></button>
        </div>
        </p>

        <script>
            // Ensure fields are locked down as soon as possible and again on load
            function wcaLockFields() {
                var emailInput = document.getElementById('account_email');
                var phoneInput = document.getElementById('billing_phone');

                if (emailInput) {
                    emailInput.readOnly = true;
                    emailInput.setAttribute('readonly', 'readonly');
                    emailInput.style.background = '#f8fafc';
                    emailInput.style.color = '#64748b';
                    emailInput.style.cursor = 'default';
                }
                if (phoneInput) {
                    phoneInput.readOnly = true;
                    phoneInput.setAttribute('readonly', 'readonly');
                }
            }

            document.addEventListener('DOMContentLoaded', function () {
                wcaLockFields();

                var emailLabel = document.querySelector('label[for="account_email"]');
                var emailInput = document.getElementById('account_email');

                if (emailLabel && !document.getElementById('wca-email-btn-container')) {
                    emailLabel.insertAdjacentHTML('beforeend', '<?php echo addslashes($email_badge); ?>');

                    if (emailInput) {
                        var container = document.createElement('div');
                        container.id = 'wca-email-btn-container';
                        container.style.marginTop = '12px';

                        var verifyBtn = document.createElement('button');
                        verifyBtn.type = 'button';
                        verifyBtn.className = 'wca-verify-action-btn';
                        verifyBtn.textContent = '<?php echo !$email_verified ? esc_html__('Verify email address', 'wca-auth-engine') : esc_html__('Update email address', 'wca-auth-engine'); ?>';
                        verifyBtn.addEventListener('click', function (e) {
                            e.preventDefault();
                            document.getElementById('wca-modal-profile-update').removeAttribute('hidden');
                            if (window._wcaProfileUpdateComponent) {
                                window._wcaProfileUpdateComponent.verifyMode = 'email';
                                window._wcaProfileUpdateComponent.newEmail = '<?php echo esc_js($current_email); ?>';
                                window._wcaProfileUpdateComponent.newPhone = '';
                                window._wcaProfileUpdateComponent.phoneNumber = '';
                            }
                        });

                        container.appendChild(verifyBtn);

                        // Try to insert after the field description if it exists
                        var nextEl = emailInput.nextElementSibling;
                        if (nextEl && nextEl.classList && nextEl.classList.contains('description')) {
                            nextEl.parentNode.insertBefore(container, nextEl.nextSibling);
                        } else {
                            emailInput.parentNode.insertBefore(container, emailInput.nextSibling);
                        }
                    }
                }
            });
            window.addEventListener('load', wcaLockFields);
        </script>
        <?php
    }

    /**
     * Hooked to: woocommerce_account_dashboard
     */
    public static function render_dashboard_status(): void
    {
        $user_id = get_current_user_id();
        $email_verified = get_user_meta($user_id, 'billing_email_verified', true);
        $phone_verified = get_user_meta($user_id, 'billing_phone_verified', true);

        $status_color = ($email_verified && $phone_verified) ? '#0a7a0a' : '#b86000';
        $status_text = ($email_verified && $phone_verified)
            ? esc_html__('Fully Verified ', 'wca-auth-engine')
            : esc_html__('Action Required ', 'wca-auth-engine');

        $message = '';
        if ($email_verified && $phone_verified) {
            $message = esc_html__('Your email and mobile phone are verified. Your account is fully secure.', 'wca-auth-engine');
        } elseif (!$email_verified && !$phone_verified) {
            $message = sprintf(
                __('Your email and mobile phone are unverified. Please <a href="%s">update your account details</a> to verify them.', 'wca-auth-engine'),
                esc_url(wc_get_account_endpoint_url('edit-account'))
            );
        } else {
            $unverified = !$email_verified ? 'email' : 'mobile phone';
            $message = sprintf(
                __('Your %1$s is unverified. Please <a href="%2$s">update your account details</a> to verify it.', 'wca-auth-engine'),
                $unverified,
                esc_url(wc_get_account_endpoint_url('edit-account'))
            );
        }
        ?>
        <div class="woocommerce-message wca-dashboard-status" style="border-top-color: <?php echo esc_attr($status_color); ?>;">
            <strong><?php echo esc_html__('Account Status:', 'wca-auth-engine'); ?></strong>
            <span style="color: <?php echo esc_attr($status_color); ?>; font-weight: bold;"><?php echo $status_text; ?></span>
            <p style="margin-top: 5px; margin-bottom: 0;"><?php echo wp_kses_post($message); ?></p>
        </div>
        <?php
    }
}
