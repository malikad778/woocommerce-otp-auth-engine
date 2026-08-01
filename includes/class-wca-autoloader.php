<?php
defined('ABSPATH') || exit;

/**
 * WCA_Autoloader - PSR-4-style class loader for the WCA Auth Engine plugin.
 *
 * Maps class names prefixed with WCA_ to their corresponding files under
 * the /includes/ directory using WordPress class-filename conventions
 * (class-wca-{slug}.php).
 */
class WCA_Autoloader
{

    /**
     * Map of class name => relative file path (from WCA_INCLUDES).
     * Explicit map avoids string-munging bugs and is fast.
     */
    private static array $class_map = [
        // Core
        'WCA_Constants' => 'class-wca-constants.php',
        'WCA_Activator' => 'class-wca-activator.php',
        'WCA_Deactivator' => 'class-wca-deactivator.php',

        // API
        'WCA_API_Router' => 'api/class-wca-api-router.php',
        'WCA_Endpoint_Register' => 'api/endpoints/class-wca-endpoint-register.php',
        'WCA_Endpoint_Login' => 'api/endpoints/class-wca-endpoint-login.php',
        'WCA_Endpoint_OTP' => 'api/endpoints/class-wca-endpoint-otp.php',
        'WCA_Endpoint_Profile' => 'api/endpoints/class-wca-endpoint-profile.php',
        'WCA_Endpoint_Password' => 'api/endpoints/class-wca-endpoint-password.php',
        'WCA_Endpoint_Add_Phone' => 'api/endpoints/class-wca-endpoint-add-phone.php',

        // Registration
        'WCA_Registration_Pipeline' => 'registration/class-wca-registration-pipeline.php',
        'WCA_Registration_Validator' => 'registration/class-wca-registration-validator.php',
        'WCA_Registration_Completer' => 'registration/class-wca-registration-completer.php',

        // Auth
        'WCA_Auth_Engine' => 'auth/class-wca-auth-engine.php',
        'WCA_Identifier_Resolver' => 'auth/class-wca-identifier-resolver.php',
        'WCA_Session_Manager' => 'auth/class-wca-session-manager.php',

        // OTP
        'WCA_OTP_Generator' => 'otp/class-wca-otp-generator.php',
        'WCA_OTP_Validator' => 'otp/class-wca-otp-validator.php',
        'WCA_OTP_Dispatcher' => 'otp/class-wca-otp-dispatcher.php',

        // Integrations
        'WCA_TextMagic_Client' => 'integrations/class-wca-textmagic-client.php',

        // Email
        'WCA_Email_Client' => 'email/class-wca-email-client.php',
        'WCA_Template_Engine' => 'email/class-wca-template-engine.php',

        // Transient
        'WCA_Transient_Store' => 'transient/class-wca-transient-store.php',
        'WCA_Transient_Janitor' => 'transient/class-wca-transient-janitor.php',

        // Checkout
        'WCA_Checkout_Guard' => 'checkout/class-wca-checkout-guard.php',
        'WCA_Checkout_Field_Locker' => 'checkout/class-wca-checkout-field-locker.php',

        // Profile
        'WCA_Profile_Update_Manager' => 'profile/class-wca-profile-update-manager.php',
        'WCA_Profile_Verifier' => 'profile/class-wca-profile-verifier.php',

        // Security
        'WCA_Rate_Limiter' => 'security/class-wca-rate-limiter.php',
        'WCA_Recaptcha' => 'security/class-wca-recaptcha.php',
        'WCA_Sanitizer' => 'security/class-wca-sanitizer.php',
        'WCA_Registration_Guard' => 'security/class-wca-registration-guard.php',

        // Migration
        'WCA_Account_Migrator' => 'migration/class-wca-account-migrator.php',

        // Admin
        'WCA_Network_Admin' => 'admin/class-wca-network-admin.php',
        'WCA_Settings' => 'admin/class-wca-settings.php',
        'WCA_Log_Viewer' => 'admin/class-wca-log-viewer.php',
        'WCA_User_Table_Columns' => 'admin/class-wca-user-table-columns.php',
        'WCA_Admin_User_Tools' => 'admin/class-wca-admin-user-tools.php',
        'WCA_Email_Test_Controller' => 'admin/class-wca-email-test-controller.php',
        'WCA_Login_Notify' => 'admin/class-wca-login-notify.php',

        // Logging
        'WCA_Logger' => 'logging/class-wca-logger.php',
    ];

    public static function init(): void
    {
        spl_autoload_register([self::class, 'load']);
    }

    public static function load(string $class_name): void
    {
        if (!isset(self::$class_map[$class_name])) {
            return;
        }
        $file = WCA_INCLUDES . self::$class_map[$class_name];
        if (file_exists($file)) {
            require_once $file;
        }
    }
}
