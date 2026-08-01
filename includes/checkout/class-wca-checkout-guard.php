<?php
defined('ABSPATH') || exit;

class WCA_Checkout_Guard
{

    public static function guard(): void
    {
        // Only run on the checkout page, and exclude order-received/order-pay pages.
        if (!is_checkout() || is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received')) {
            return;
        }
        // -- GUEST --------------------------------------------------------
        if (!is_user_logged_in()) {
            WCA_Logger::log('CHECKOUT_ACCESS_BLOCKED', [
                'reason' => 'not_logged_in',
                'ip' => WCA_Rate_Limiter::get_client_ip(),
            ]);

            // If modal param is not exactly 'login', redirect to checkout with 'login'.
            if (empty($_GET['wca_modal']) || $_GET['wca_modal'] !== 'login') {
                wp_safe_redirect(
                    add_query_arg('wca_modal', 'login', wc_get_checkout_url())
                );
                exit;
            }

            // Modal param is in URL - we're on checkout with modal open.
            // Suppress WC's own login toggle (we replaced it).
            // Hide checkout form behind modal - guest can't fill it anyway.
            add_filter('woocommerce_checkout_show_login_reminder', '__return_false');
            add_action('wp_head', [self::class, 'inject_guest_checkout_css']);

            return; //  HARD STOP. Never fall through to logged-in checks.
        }

        // -- LOGGED IN -----------------------------------------------------
        $user_id = get_current_user_id();

        if (get_user_meta($user_id, 'wca_account_locked', true)) {
            WCA_Logger::log('CHECKOUT_ACCESS_BLOCKED', [
                'reason' => 'account_locked',
                'user_id' => $user_id,
            ]);
            wp_logout();
            wp_safe_redirect(
                add_query_arg('wca_error', rawurlencode(WCA_Admin_User_Tools::get_suspension_message($user_id)), wc_get_checkout_url())
            );
            exit;
        }

        // Admins and Super Admins bypass verification requirements.
        if (user_can($user_id, 'manage_options')) {
            return;
        }

        if (get_user_meta($user_id, 'wca_needs_reverification', true)) {
            // Before blocking, check if the account is actually fully verified.
            // The flag may be stale (e.g. from migration, or password login which never clears it).
            // We trust email + phone verified as the source of truth - NOT account_status,
            // because the dashboard "Fully Verified" badge is based on those two flags only,
            // and account_status may legitimately be empty on old/legacy accounts.
            $email_verified = get_user_meta($user_id, 'billing_email_verified', true);
            $phone_verified = get_user_meta($user_id, 'billing_phone_verified', true);

            WCA_Logger::log('REVERIFY_FLAG_CHECK', [
                'user_id' => $user_id,
                'account_status' => get_user_meta($user_id, 'account_status', true),
                'email_verified' => $email_verified,
                'phone_verified' => $phone_verified,
            ]);

            if ($email_verified && $phone_verified) {
                // Both contact methods are verified - flag is stale. Clear it and ensure
                // account_status is approved so all three are in sync going forward.
                delete_user_meta($user_id, 'wca_needs_reverification');
                update_user_meta($user_id, 'account_status', 'approved');
                WCA_Logger::log('STALE_REVERIFY_FLAG_CLEARED', ['user_id' => $user_id]);
                // Fall through - let the rest of guard() run normally.
            } else {
                WCA_Logger::log('CHECKOUT_ACCESS_BLOCKED', [
                    'reason' => 'needs_reverification',
                    'user_id' => $user_id,
                ]);
                wp_safe_redirect(
                    add_query_arg('wca_modal', 'reverify', wc_get_page_permalink('myaccount'))
                );
                exit;
            }
        }


        $status = get_user_meta($user_id, 'account_status', true);

        // Block any status that is not explicitly approved (including empty).
        if ($status !== 'approved') {
            WCA_Logger::log('CHECKOUT_ACCESS_BLOCKED', [
                'reason' => 'account_not_approved',
                'user_id' => $user_id,
                'status' => $status,
            ]);
            wp_safe_redirect(
                add_query_arg('wca_modal', 'reverify', wc_get_page_permalink('myaccount'))
            );
            exit;
        }

        // -- PHONE NUMBER MISSING OR UNVERIFIED -------------------------------
        // Legacy accounts that pre-date the plugin may have no phone number.
        // Force them to add and verify one before they can checkout.
        $phone = get_user_meta($user_id, 'billing_phone', true);
        $phone_verified = get_user_meta($user_id, 'billing_phone_verified', true);

        if (empty(trim((string) $phone))) {
            WCA_Logger::log('CHECKOUT_ACCESS_BLOCKED', [
                'reason' => 'no_phone_number',
                'user_id' => $user_id,
            ]);
            if (empty($_GET['wca_modal']) || $_GET['wca_modal'] !== 'add-phone') {
                wp_safe_redirect(
                    add_query_arg('wca_modal', 'add-phone', wc_get_checkout_url())
                );
                exit;
            }
            // Modal is already open - hide the checkout form.
            add_action('wp_head', [self::class, 'inject_guest_checkout_css']);
            return;
        }

        if (!$phone_verified) {
            WCA_Logger::log('CHECKOUT_ACCESS_BLOCKED', [
                'reason' => 'unverified_phone_number',
                'user_id' => $user_id,
            ]);
            wp_safe_redirect(
                add_query_arg('wca_modal', 'reverify', wc_get_page_permalink('myaccount'))
            );
            exit;
        }

        // -- EMAIL UNVERIFIED ------------------------------------------------------
        // Legacy accounts may have billing_email_verified = 0 (pre-dates the plugin).
        // Route to a dedicated email verification modal so the user understands
        // exactly what is needed - avoids the generic reverify loop.
        $email_verified = get_user_meta($user_id, 'billing_email_verified', true);

        if (!$email_verified) {
            WCA_Logger::log('CHECKOUT_ACCESS_BLOCKED', [
                'reason' => 'unverified_email',
                'user_id' => $user_id,
            ]);
            wp_safe_redirect(
                add_query_arg('wca_modal', 'reverify-email', wc_get_page_permalink('myaccount'))
            );
            exit;
        }
    }

    public static function inject_guest_checkout_css(): void
    {
        echo '<style>
            /* Hide WC checkout form content while modal is active */
            .woocommerce-checkout form.woocommerce-checkout,
            .woocommerce-checkout .col2-set,
            .woocommerce-checkout #order_review,
            .woocommerce-checkout #order_review_heading { display: none !important; }

            /* Keep the WC login toggle visible - we hijack it */
            .woocommerce-form-login-toggle { display: block !important; }

            /* Lock both possible modals - no dismiss on checkout */
            #wca-modal-login,
            #wca-modal-add-phone { pointer-events: all; }
        </style>';
    }

    public static function validate_checkout(): void
    {
        if (!is_user_logged_in()) {
            wc_add_notice(__('You must be logged in to place an order.', 'wca-auth-engine'), 'error');
            return;
        }

        $user_id = get_current_user_id();

        if (get_user_meta($user_id, 'wca_account_locked', true)) {
            wp_logout();
            wc_add_notice(WCA_Admin_User_Tools::get_suspension_message($user_id), 'error');
            return;
        }

        // Admins and Super Admins bypass verification requirements.
        if (user_can($user_id, 'manage_options')) {
            return;
        }

        if (get_user_meta($user_id, 'wca_needs_reverification', true)) {
            // Same stale-flag check as guard() - clear silently if account is fully verified.
            $is_actually_verified =
                get_user_meta($user_id, 'account_status', true) === 'approved' &&
                get_user_meta($user_id, 'billing_email_verified', true) &&
                get_user_meta($user_id, 'billing_phone_verified', true);

            if ($is_actually_verified) {
                delete_user_meta($user_id, 'wca_needs_reverification');
            } else {
                wc_add_notice(__('Your account requires re-verification. Please verify your contact details.', 'wca-auth-engine'), 'error');
                return;
            }
        }

        $status = get_user_meta($user_id, 'account_status', true);
        if ($status !== 'approved') {
            wc_add_notice(__('Your account is not approved. Please verify your contact details.', 'wca-auth-engine'), 'error');
            return;
        }

        $phone = get_user_meta($user_id, 'billing_phone', true);
        $phone_verified = get_user_meta($user_id, 'billing_phone_verified', true);

        if (empty(trim((string) $phone))) {
            wc_add_notice(__('Please add your mobile phone number before placing an order.', 'wca-auth-engine'), 'error');
            return;
        }
        if (!$phone_verified) {
            wc_add_notice(__('Please verify your mobile phone number before placing an order.', 'wca-auth-engine'), 'error');
            return;
        }

        $email_verified = get_user_meta($user_id, 'billing_email_verified', true);
        if (!$email_verified) {
            wc_add_notice(__('Please verify your email address before placing an order.', 'wca-auth-engine'), 'error');
            return;
        }
    }
}
