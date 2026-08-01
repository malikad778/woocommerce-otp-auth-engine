<?php
defined('ABSPATH') || exit;

/**
 * WCA_Checkout_Field_Locker - Renders billing_email and billing_phone as
 * readonly fields on the checkout form, pre-filled from the user's verified profile.
 */
class WCA_Checkout_Field_Locker
{

    // --- Filter: woocommerce_checkout_fields ------------------------------

    public static function lock_fields(array $fields): array
    {
        if (!is_user_logged_in()) {
            return $fields;
        }

        $user_id = get_current_user_id();
        $email = wp_get_current_user()->user_email;
        $phone = get_user_meta($user_id, 'billing_phone', true);
        $update_url = esc_url(wc_get_account_endpoint_url('edit-account'));

        // Lock billing_email.
        if (isset($fields['billing']['billing_email'])) {
            $fields['billing']['billing_email']['default'] = $email;
            $fields['billing']['billing_email']['custom_attributes'] = [
                'readonly' => 'readonly',
                'aria-label' => __('Verified email (locked)', 'wca-auth-engine'),
            ];
            $fields['billing']['billing_email']['class'][] = 'wca-locked-field';
            $fields['billing']['billing_email']['description'] = sprintf(
                __('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;margin-bottom:2px;opacity:0.6;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg> To update your verified email address, please visit your <a href="%s">Account Details</a>.', 'wca-auth-engine'),
                $update_url
            );
        }

        // Lock billing_phone.
        if (isset($fields['billing']['billing_phone']) && $phone) {
            $fields['billing']['billing_phone']['default'] = $phone;
            $fields['billing']['billing_phone']['custom_attributes'] = [
                'readonly' => 'readonly',
                'aria-label' => __('Verified phone (locked)', 'wca-auth-engine'),
            ];
            $fields['billing']['billing_phone']['class'][] = 'wca-locked-field';
            $fields['billing']['billing_phone']['description'] = sprintf(
                __('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;margin-bottom:2px;opacity:0.6;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg> To update your verified mobile number, please visit your <a href="%s">Account Details</a>.', 'wca-auth-engine'),
                $update_url
            );
        }

        return $fields;
    }

    // --- Action: woocommerce_after_checkout_fields ------------------------

    public static function render_locked_fields(): void
    {
        // Replaced by inline field descriptions in lock_fields().
    }

    // --- Filter: woocommerce_checkout_posted_data -------------------------

    /**
     * Enforce the locked data on form submission to prevent WooCommerce from
     * overwriting verified profile fields if the DOM was altered or inputs were empty.
     */
    public static function enforce_locked_data(array $data): array
    {
        if (!is_user_logged_in()) {
            return $data;
        }

        $user_id = get_current_user_id();
        $email = wp_get_current_user()->user_email;
        $phone = get_user_meta($user_id, 'billing_phone', true);

        if ($email) {
            $data['billing_email'] = $email;
        }

        if ($phone) {
            $data['billing_phone'] = $phone;
        }

        return $data;
    }
}
