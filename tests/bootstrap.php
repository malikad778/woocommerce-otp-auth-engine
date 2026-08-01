<?php
/**
 * PHPUnit Bootstrap for WCA Auth Engine.
 * Provides lightweight mocks for WordPress / WooCommerce functions in standalone CLI mode.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

define('WP_CONTENT_DIR', __DIR__ . '/../wp-content');
define('WCA_VERSION', '1.0.0');
define('WCA_FILE', __DIR__ . '/../wca-auth-engine.php');
define('WCA_DIR', __DIR__ . '/../');
define('WCA_URL', 'https://example.com/wp-content/plugins/wca-auth-engine/');
define('WCA_INCLUDES', WCA_DIR . 'includes/');
define('WCA_OTP_TTL', 600);
define('WCA_NAMESPACE', 'custom-auth/v1');

// Mock core WordPress functions if not loaded in full WP environment
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {}
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {}
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) {
        return $value;
    }
}
if (!function_exists('defined')) {
    function defined($name) { return true; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags((string)$str));
    }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }
}
if (!function_exists('is_email')) {
    function is_email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
if (!function_exists('wp_hash_password')) {
    function wp_hash_password($password) {
        return password_hash($password, PASSWORD_BCRYPT);
    }
}
if (!function_exists('wp_check_password')) {
    function wp_check_password($password, $hash) {
        return password_verify($password, $hash);
    }
}
if (!function_exists('get_site_option')) {
    function get_site_option($key, $default = false) {
        return $default;
    }
}
if (!class_exists('WP_User')) {
    class WP_User {
        public int $ID = 1;
        public string $user_email = 'test@example.com';
        public string $user_login = 'testuser';
    }
}
if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) {
        if ($field === 'email' && $value === 'test@example.com') {
            return new WP_User();
        }
        if ($field === 'login' && $value === 'testuser') {
            return new WP_User();
        }
        return false;
    }
}

// Require autoloader and core constants
require_once WCA_INCLUDES . 'class-wca-constants.php';
require_once WCA_INCLUDES . 'security/class-wca-sanitizer.php';
require_once WCA_INCLUDES . 'otp/class-wca-otp-generator.php';
require_once WCA_INCLUDES . 'auth/class-wca-identifier-resolver.php';
