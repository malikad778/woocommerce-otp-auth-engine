<?php
/**
 * Plugin Name:       WCA Auth Engine
 * Plugin URI:        #
 * Description:       Complete authentication engine for WooCommerce Multisite. Replaces seven conflicting plugins with a unified, decoupled auth pipeline featuring dual-factor verification (native wp_mail email + TextMagic SMS) and pre-database registration gating.
 * Version:           1.0.0
 * Author:            Malik Adnan Haider - WebWhizy
 * Author URI:        https://codebyadnan.tech/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wca-auth-engine
 * Domain Path:       /languages
 * Requires PHP:      8.1
 * Requires at least: 6.0
 * WC requires at least: 7.0
 * Network:           true
 */

defined('ABSPATH') || exit;

// -------------------------------------------------------------
// Constants
// -------------------------------------------------------------
define('WCA_VERSION', '1.0.0');
define('WCA_FILE', __FILE__);
define('WCA_DIR', plugin_dir_path(__FILE__));
define('WCA_URL', plugin_dir_url(__FILE__));
define('WCA_INCLUDES', WCA_DIR . 'includes/');
define('WCA_LOG_PATH', WP_CONTENT_DIR . '/uploads/logs/auth_engine.log');
define('WCA_OTP_TTL', 600);   // 10 minutes
define('WCA_NAMESPACE', 'custom-auth/v1');

// -------------------------------------------------------------
// Autoloader
// -------------------------------------------------------------
require_once WCA_INCLUDES . 'class-wca-autoloader.php';
WCA_Autoloader::init();

// -------------------------------------------------------------
// Activation / Deactivation Hooks
// -------------------------------------------------------------
register_activation_hook(__FILE__, ['WCA_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['WCA_Deactivator', 'deactivate']);

// -------------------------------------------------------------
// Bootstrap on plugins_loaded - ensure WooCommerce is present
// -------------------------------------------------------------
add_action('plugins_loaded', 'wca_boot', 5);

function wca_boot(): void
{

    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>WCA Auth Engine</strong> requires WooCommerce to be active.</p></div>';
        });
        return;
    }

    wca_maybe_run_version_migrations();

    // Block native multisite signup (wp-signup.php / wp-activate.php) before
    // either script's own body can run. These are standalone entry files -
    // they never fire registration_errors/woocommerce_process_registration_errors
    // below, and a bot hitting them directly creates a real account with zero
    // OTP/email/phone verification, entirely outside WCA_Registration_Pipeline.
    add_action('init', 'wca_block_native_multisite_signup');

    // Add after the WooCommerce check block
    wca_suppress_wc_login_form();

    // If a user logs in but has no account_status (e.g. created by admin or unmigrated legacy user), force them to verify.
    add_action('wp_login', function (string $user_login, WP_User $user): void {
        if (!get_user_meta($user->ID, 'account_status', true) && !is_super_admin($user->ID)) {
            update_user_meta($user->ID, 'wca_needs_reverification', 1);
        }
    }, 10, 2);

    // Intercept native WP login URL  redirect to /my-account/ modal trigger.
    add_filter('login_url', 'wca_override_login_url', 20, 3);
    add_filter('register_url', 'wca_override_register_url', 20, 1);

    // Completely block native WordPress frontend registration (bots hitting wp-login.php).
    add_filter('registration_errors', function (WP_Error $errors) {
        $errors->add('registration_disabled', 'Registration is strictly limited to the official secure login flow.');
        return $errors;
    }, 99, 1);

    // Completely block WooCommerce native frontend registration (bots hitting /my-account/).
    add_filter('woocommerce_process_registration_errors', function (WP_Error $errors) {
        $errors->add('registration_disabled', 'Registration is strictly limited to the official secure login flow.');
        return $errors;
    }, 99, 1);

    // Fail-safe: if any account still slips through outside WCA_Registration_Pipeline
    // (a bypass none of the blocks above caught), delete it automatically once the
    // request finishes. Never touches accounts an admin creates by hand.
    WCA_Registration_Guard::init();

    // -- Compatibility: customize-my-account-for-woocommerce ------------------
    // If active, its template may override the edit-account form button ID.
    // We swap in a generic selector-based JS initializer to avoid UI conflict.
    add_action('plugins_loaded', 'wca_cmaf_compat_patch', 20);

    // REST API router.
    add_action('rest_api_init', ['WCA_API_Router', 'register_routes']);

    // Checkout guards.
    add_action('template_redirect', ['WCA_Checkout_Guard', 'guard']);
    add_action('woocommerce_checkout_process', ['WCA_Checkout_Guard', 'validate_checkout']);
    add_filter('woocommerce_checkout_fields', ['WCA_Checkout_Field_Locker', 'lock_fields']);
    add_action('woocommerce_after_checkout_fields', ['WCA_Checkout_Field_Locker', 'render_locked_fields']);
    add_filter('woocommerce_checkout_posted_data', ['WCA_Checkout_Field_Locker', 'enforce_locked_data']);
    add_filter('woocommerce_checkout_registration_enabled', '__return_false');


    // Profile update interceptor and field injection.
    add_filter('woocommerce_billing_fields', ['WCA_Profile_Update_Manager', 'make_billing_fields_readonly'], 100);
    add_action('woocommerce_edit_account_form', ['WCA_Profile_Update_Manager', 'render_phone_field'], 10);
    add_action('woocommerce_account_dashboard', ['WCA_Profile_Update_Manager', 'render_dashboard_status'], 10);
    add_action('woocommerce_account_dashboard', ['WCA_Login_Notify', 'render_dashboard_notice'], 11);

    // Enqueue frontend assets.
    add_action('wp_enqueue_scripts', 'wca_enqueue_frontend_assets');
    add_filter('script_loader_tag', function ($tag, $handle) {
        if ('alpinejs' === $handle && false === strpos($tag, 'defer')) {
            return str_replace(' src', ' defer src', $tag);
        }
        return $tag;
    }, 10, 2);

    add_action('wp_footer', 'wca_render_modal_templates');

    // Admin-only.

    // Admin-only.
    if (is_admin() || is_network_admin()) {
        add_action('network_admin_menu', ['WCA_Network_Admin', 'register_menu']);
        add_filter('manage_users_columns', ['WCA_User_Table_Columns', 'add_column']);
        add_filter('manage_users_custom_column', ['WCA_User_Table_Columns', 'render_column'], 10, 3);

        if (is_multisite()) {
            add_filter('manage_users-network_columns', ['WCA_User_Table_Columns', 'add_column']);
            add_filter('manage_users-network_custom_column', ['WCA_User_Table_Columns', 'render_column'], 10, 3);
        }
        WCA_User_Table_Columns::init();
        add_action('admin_enqueue_scripts', 'wca_enqueue_admin_assets');
        add_action('network_admin_enqueue_scripts', 'wca_enqueue_admin_assets');
        WCA_Admin_User_Tools::init();
        WCA_Email_Test_Controller::init();
        WCA_Login_Notify::init();
    }

    // Cron janitor.
    add_action('wca_transient_sweep', ['WCA_Transient_Janitor', 'sweep']);
    add_action('wca_daily_cleanup', ['WCA_Logger', 'cleanup_old_logs']);

    // Catch frontend verify_email link clicks.
    add_action('template_redirect', 'wca_handle_verify_email_link');

    // Strip verification flags when contact details change via WC or WP profile forms.
    // Note: Only 'updated_user_meta' is hooked (value actually changed).
    // 'added_user_meta' is intentionally NOT hooked - first-time creation of the meta key
    // (e.g. during registration) must not strip verification flags that are set immediately after.
    add_action('updated_user_meta', 'wca_strip_verification_on_meta_update', 10, 4);
    add_action('profile_update', 'wca_strip_verification_on_email_update', 10, 2);
}

// -------------------------------------------------------------
// One-time cleanup: Brevo  native email engine migration
// -------------------------------------------------------------
function wca_maybe_run_version_migrations(): void
{
    $stored_version = get_site_option('wca_plugin_version', '');
    if ($stored_version === WCA_VERSION) {
        return;
    }

    delete_transient('wca_brevo_active_sender_cache');
    foreach ([
        'wca_brevo_api_key',
        'wca_brevo_template_registration',
        'wca_brevo_template_login',
        'wca_brevo_template_forgot_password',
        'wca_brevo_template_profile_update',
    ] as $key) {
        delete_site_option($key);
    }

    update_site_option('wca_plugin_version', WCA_VERSION);
}

function wca_strip_verification_on_meta_update(int $meta_id, int $object_id, string $meta_key, $_meta_value): void
{
    if ($meta_key === 'billing_phone') {
        delete_user_meta($object_id, 'billing_phone_verified');
    } elseif ($meta_key === 'billing_email') {
        delete_user_meta($object_id, 'billing_email_verified');
    }
}

function wca_strip_verification_on_email_update(int $user_id, WP_User $old_user_data): void
{
    $new_user = get_userdata($user_id);
    if ($new_user && $new_user->user_email !== $old_user_data->user_email) {
        delete_user_meta($user_id, 'billing_email_verified');
    }
}

function wca_handle_verify_email_link(): void
{
    if (isset($_GET['wca_action']) && $_GET['wca_action'] === 'verify_email') {
        $session_token = sanitize_text_field($_GET['session_token'] ?? '');
        $token = sanitize_text_field($_GET['token'] ?? '');

        $result = WCA_Registration_Pipeline::verify_email($session_token, $token);

        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg([
                'wca_modal' => 'register',
                'wca_error' => rawurlencode($result->get_error_message()),
            ], wc_get_page_permalink('myaccount')));
            exit;
        }

        // Determine the base redirect URL. Fall back to my-account.
        $base_url = wc_get_page_permalink('myaccount');
        if (!empty($result['return_url'])) {
            $parsed = wp_parse_url($result['return_url']);
            // Make sure it's a valid local path (e.g. /checkout/)
            if (!empty($parsed['path']) && str_starts_with($parsed['path'], '/')) {
                $base_url = home_url($parsed['path']);
            }
        }

        // Auto-complete if both are verified.
        if (!empty($result['both_verified'])) {
            $complete = WCA_Registration_Pipeline::complete($session_token);
            if (is_wp_error($complete)) {
                wp_safe_redirect(add_query_arg([
                    'wca_modal' => 'register',
                    'wca_error' => rawurlencode($complete->get_error_message()),
                ], $base_url));
                exit;
            }

            // Log the user in!
            wp_set_current_user($complete['user_id']);
            wp_set_auth_cookie($complete['user_id'], true);

            wp_safe_redirect(add_query_arg('wca_verified', '1', $base_url));
            exit;
        }

        // Email verified, awaiting SMS
        wp_safe_redirect(add_query_arg([
            'wca_modal' => 'register',
            'wca_email_verified' => '1',
            'wca_session' => rawurlencode($session_token),
        ], $base_url));
        exit;
    }
}

// -------------------------------------------------------------
// Login URL Override
// -------------------------------------------------------------
function wca_override_login_url(string $login_url, string $redirect, bool $force_reauth): string
{
    $base = wc_get_page_permalink('myaccount');
    if ($redirect) {
        $base = add_query_arg('redirect_to', rawurlencode($redirect), $base);
    }
    $base = add_query_arg('wca_modal', 'login', $base);
    return $base;
}

function wca_override_register_url(string $register_url): string
{
    $base = wc_get_page_permalink('myaccount');
    $base = add_query_arg('wca_modal', 'register', $base);
    return $base;
}

// -------------------------------------------------------------
// Block native multisite signup entry scripts
// -------------------------------------------------------------
/**
 * wp-signup.php and wp-activate.php are standalone entry files (like
 * wp-login.php) - they load the full WP + plugin stack via wp-load.php but
 * run their own page body outside the template_redirect lifecycle, and
 * never fire registration_errors or woocommerce_process_registration_errors.
 * A direct request to either creates/activates a real account through
 * wpmu_signup_user()/wpmu_activate_signup(), completely bypassing this
 * plugin's OTP/email/phone verification pipeline.
 *
 * $pagenow is set during wp-settings.php, before plugins_loaded fires, so
 * it's already reliable here even though wca_boot() itself runs on
 * plugins_loaded (priority 5).
 */
function wca_block_native_multisite_signup(): void
{
    if (!is_multisite()) {
        return;
    }

    global $pagenow;

    if (!in_array($pagenow, ['wp-signup.php', 'wp-activate.php'], true)) {
        return;
    }

    wp_safe_redirect(wca_override_register_url(''));
    exit;
}

// -------------------------------------------------------------
// Compatibility patch: customize-my-account-for-woocommerce
// -------------------------------------------------------------
function wca_cmaf_compat_patch(): void
{
    if (
        class_exists('YITH_WOOCOMMERCE_CUSTOMIZE_MY_ACCOUNT') ||
        defined('CMAF_PLUGIN_VERSION')
    ) {
        add_filter('wca_edit_account_form_selector', function () {
            return '.woocommerce-EditAccountForm, form.woocommerce-form-login, [id*="account-details"]';
        });
    }
}

// -------------------------------------------------------------
// Frontend Asset Enqueue
// -------------------------------------------------------------
function wca_enqueue_frontend_assets(): void
{
    $recaptcha_key = get_site_option('wca_recaptcha_site_key', '');

    wp_enqueue_style(
        'wca-auth',
        WCA_URL . 'frontend/css/wca-auth.css',
        [],
        WCA_VERSION
    );

    // Alpine.js v3 from CDN.
    wp_enqueue_script(
        'alpinejs',
        'https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js',
        [],
        '3',
        true   // defer = footer
    );

    wp_enqueue_script(
        'wca-auth-app',
        WCA_URL . 'frontend/js/wca-auth-app.js',
        ['alpinejs'],
        WCA_VERSION,
        true
    );

    wp_enqueue_script(
        'wca-otp-input',
        WCA_URL . 'frontend/js/wca-otp-input.js',
        ['wca-auth-app'],
        WCA_VERSION,
        true
    );

    // reCAPTCHA v3 script (only if key configured).
    if ($recaptcha_key) {
        wp_enqueue_script(
            'wca-recaptcha',
            'https://www.google.com/recaptcha/api.js?render=' . esc_attr($recaptcha_key),
            [],
            null,
            false
        );
    }

    // Pass PHP config to JS.
    wp_localize_script('wca-auth-app', 'wcaConfig', [
        'apiBase' => esc_url(rest_url(WCA_NAMESPACE)),
        'nonce' => wp_create_nonce('wp_rest'),
        'recaptchaKey' => $recaptcha_key,
        'otpTtl' => (int) get_site_option('wca_otp_ttl', WCA_OTP_TTL),
        'myAccountUrl' => wc_get_page_permalink('myaccount'),
        'checkoutUrl' => wc_get_checkout_url(),
        'siteUrl' => site_url(),
        'openModal' => sanitize_text_field($_GET['wca_modal'] ?? ''),
        'emailVerified' => !empty($_GET['wca_email_verified']),
        'sessionToken' => sanitize_text_field($_GET['wca_session'] ?? ''),
        'editFormSelector' => apply_filters('wca_edit_account_form_selector', '#save-account-details'),
        'isLoggedIn' => is_user_logged_in(),
        'dialCodes' => array_keys(WCA_Constants::get_country_dial_codes()),
    ]);
}

// -------------------------------------------------------------
// Admin Asset Enqueue
// -------------------------------------------------------------
function wca_enqueue_admin_assets(string $hook): void
{
    if (strpos($hook, 'wca-auth-engine') === false) {
        return;
    }
    wp_enqueue_style(
        'wca-admin',
        WCA_URL . 'frontend/css/wca-auth.css',
        [],
        WCA_VERSION
    );

    wp_enqueue_script(
        'wca-admin-settings',
        WCA_URL . 'frontend/js/wca-admin-settings.js',
        [],
        WCA_VERSION,
        true
    );

    $sample_params = [];
    foreach (WCA_Template_Engine::template_keys() as $template_key) {
        $sample_params[$template_key] = WCA_Template_Engine::sample_params($template_key);
    }

    wp_localize_script('wca-admin-settings', 'wcaAdminSettings', [
        // Force the current scheme (https) on the ajax URL so the browser
        // does not block it as mixed-content. get_admin_url() can return http://
        // when WordPress siteurl is stored as http:// in the DB even if the live
        // site runs over https, which causes fetch() to throw "Network error"
        // even though the server processed the request correctly.
        'ajaxUrl' => set_url_scheme(get_admin_url(get_main_site_id(), 'admin-ajax.php')),
        'testEmailNonce' => wp_create_nonce('wca_test_email'),
        'loginNotifyNonce' => wp_create_nonce('wca_login_notify'),
        'sampleParams' => $sample_params,
    ]);
}

// -------------------------------------------------------------
// Render Modal Templates in Footer
// -------------------------------------------------------------
function wca_render_modal_templates(): void
{
    $is_logged_in = is_user_logged_in();

    $templates = [
        'modal-profile-update',
        'modal-add-phone',
    ];

    if ($is_logged_in) {
        // Reverify modal: shown when ?wca_modal=reverify is triggered by the checkout guard
        // for logged-in users who need re-verification (e.g. after migration).
        $templates[] = 'modal-otp-verify';
        // Email reverify modal: shown when ?wca_modal=reverify-email is triggered
        // for logged-in users whose email is unverified (legacy accounts).
        $templates[] = 'modal-reverify-email';
    }

    if (!$is_logged_in) {
        $templates[] = 'modal-register';
        $templates[] = 'modal-login';
        $templates[] = 'modal-forgot-password';
        $templates[] = 'modal-otp-verify';
    }

    foreach ($templates as $tpl) {
        $path = WCA_DIR . 'frontend/templates/' . $tpl . '.php';
        if (file_exists($path)) {
            include $path;
        }
    }
}


function wca_suppress_wc_login_form(): void
{
    add_action('wp_head', function () {
        echo '<style>
            .woocommerce-MyAccount-content .woocommerce-form-login,
            .woocommerce-MyAccount-content .woocommerce-form-register,
            .woocommerce-account .woocommerce > .woocommerce-form-login,
            .woocommerce-account .woocommerce > .woocommerce-form-register,
            .woocommerce-account .u-columns.col2-set { display: none !important; }
        </style>';
    });
}



/**
 * Patch for WooCommerce Multistore bug in WC 8.0+
 * Newer WooCommerce versions do not fire 'woocommerce_update_order' when an order changes from draft to processing during checkout.
 * This patch manually triggers the Multistore sync task on new orders.
 */
function wca_force_multistore_sync_on_new_order($order_id, $order = null): void
{
    if (class_exists('WC_Multistore_Order_Hooks_Child') && function_exists('as_enqueue_async_action')) {
        // Only run on child sites where Multistore is active
        if (WOO_MULTISTORE()->site->get_type() == 'child') {
            if (WOO_MULTISTORE()->settings['enable-order-import'] == 'yes') {
                $site_settings = WOO_MULTISTORE()->site->get_settings();
                if (isset($site_settings['child_inherit_changes_fields_control__import_order']) && $site_settings['child_inherit_changes_fields_control__import_order'] == 'yes') {
                    // Check if a task is already pending to avoid duplicates
                    $scheduled_actions = as_get_scheduled_actions(
                        array(
                            'hook' => 'wc_multistore_send_original_order',
                            'args' => array('order_id' => (int) $order_id),
                            'group' => 'wc_multistore',
                            'status' => ActionScheduler_Store::STATUS_PENDING,
                        ),
                        'ids'
                    );

                    if (empty($scheduled_actions)) {
                        as_enqueue_async_action('wc_multistore_send_original_order', array('order_id' => (int) $order_id), 'wc_multistore');
                    }
                }
            }
        }
    }
}
add_action('woocommerce_new_order', 'wca_force_multistore_sync_on_new_order', 10, 2);

/**
 * Bypass Master Site HTTP Basic Auth for WooCommerce Multistore Sync
 * Injects the staging site credentials into all background sync requests from Child sites.
 */
add_filter('http_request_args', function (array $args, string $url): array {
    // Check if the request is going to the staging master site
    if (strpos($url, 'cheapkamagrauk91761.e.wpstage.net') !== false) {
        if (!isset($args['headers'])) {
            $args['headers'] = [];
        }
        $args['headers']['Authorization'] = 'Basic ' . base64_encode('blogvault:fac45854');
    }
    return $args;
}, 10, 2);
