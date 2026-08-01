<?php
defined('ABSPATH') || exit;

/**
 * WCA_Admin_User_Tools - Manual override controls on the WP User Edit screen.
 *
 * Provides two independent governance actions:
 *   1. Force Re-verification  - strips approval, marks account for re-verify.
 *   2. Account Lock / Unlock  - completely bans the user from logging in via
 *                               any WCA pathway (password, OTP, or standard WP).
 *
 * Security standards applied:
 *   - Every save is gated behind a wp_nonce_field (action scoped to user ID).
 *   - All operations require current_user_can('edit_user', $target_id).
 *   - Privilege escalation guard: non-super-admins cannot lock other admins.
 *   - Active sessions are instantly destroyed when an account is locked.
 *   - Reason text is sanitized and length-capped at 255 chars.
 *   - All state changes are written to the WCA audit log.
 */
class WCA_Admin_User_Tools
{
    /** User-meta key signalling a hard account lock. */
    public const LOCK_META_KEY = 'wca_account_locked';

    /** User-meta key carrying the human-readable reason for the lock. */
    private const LOCK_REASON_KEY = 'wca_account_locked_reason';

    // --- Bootstrap --------------------------------------------------------

    public static function init(): void
    {
        // Edit User screen - fields and save.
        add_action('show_user_profile', [self::class, 'render_fields']);
        add_action('edit_user_profile', [self::class, 'render_fields']);
        add_action('personal_options_update', [self::class, 'save_fields']);
        add_action('edit_user_profile_update', [self::class, 'save_fields']);

        // Users list table - quick Lock / Unlock row action links.
        add_filter('user_row_actions', [self::class, 'add_row_actions'], 10, 2);

        // admin-post handlers for the quick lock/unlock actions.
        add_action('admin_post_wca_lock_user', [self::class, 'handle_quick_lock']);
        add_action('admin_post_wca_unlock_user', [self::class, 'handle_quick_unlock']);

        // Bulk actions
        add_filter('bulk_actions-users', [self::class, 'register_bulk_actions']);
        add_filter('handle_bulk_actions-users', [self::class, 'handle_bulk_actions'], 10, 3);
        add_action('admin_notices', [self::class, 'admin_notices']);

        if (is_multisite()) {
            add_filter('ms_user_row_actions', [self::class, 'add_row_actions'], 10, 2);
            add_filter('bulk_actions-users-network', [self::class, 'register_bulk_actions']);
            add_filter('handle_bulk_actions-users-network', [self::class, 'handle_bulk_actions'], 10, 3);
            add_action('network_admin_notices', [self::class, 'admin_notices']);
        }
    }

    // --- Render -----------------------------------------------------------

    public static function render_fields(WP_User $user): void
    {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }

        $needs_reverify = (bool) get_user_meta($user->ID, 'wca_needs_reverification', true);
        $is_locked = (bool) get_user_meta($user->ID, self::LOCK_META_KEY, true);
        $lock_reason = (string) get_user_meta($user->ID, self::LOCK_REASON_KEY, true);

        // Only show lock controls if the current user outranks (or equals) the target.
        $target_is_admin = user_can($user->ID, 'manage_options');
        $current_is_admin = current_user_can('manage_options');
        $show_lock_row = !$target_is_admin || $current_is_admin;
        ?>
        <h2><?php esc_html_e('WCA Authentication Engine', 'wca-auth-engine'); ?></h2>
        <?php wp_nonce_field('wca_user_tools_' . $user->ID, 'wca_user_tools_nonce'); ?>
        <table class="form-table">

            <?php /* -- Row 1: Force Re-verification ----------------------- */ ?>
            <tr>
                <th scope="row">
                    <label for="wca_force_reverify">
                        <?php esc_html_e('Force Re-verification', 'wca-auth-engine'); ?>
                    </label>
                </th>
                <td>
                    <?php if ($needs_reverify): ?>
                        <p style="color:#c00;font-weight:bold;margin-bottom:8px;">
                            <span class="dashicons dashicons-warning" style="vertical-align:text-bottom;"></span>
                            <?php esc_html_e('User is pending re-verification and will be blocked from checkout.', 'wca-auth-engine'); ?>
                        </p>
                        <label>
                            <input type="checkbox" name="wca_cancel_reverify" id="wca_cancel_reverify" value="1">
                            <?php esc_html_e('Cancel re-verification requirement and approve account.', 'wca-auth-engine'); ?>
                        </label>
                    <?php else: ?>
                        <label>
                            <input type="checkbox" name="wca_force_reverify" id="wca_force_reverify" value="1">
                            <?php esc_html_e('Unapprove and force user to re-verify phone / email.', 'wca-auth-engine'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Use this after manually changing or removing their phone number.', 'wca-auth-engine'); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>

            <?php /* -- Row 2: Account Lock / Unlock ----------------------- */ ?>
            <?php if ($show_lock_row): ?>
                <tr>
                    <th scope="row">
                        <label for="wca_lock_account">
                            <?php esc_html_e('Account Lock', 'wca-auth-engine'); ?>
                        </label>
                    </th>
                    <td>
                        <?php if ($is_locked): ?>
                            <p
                                style="color:#c00;font-weight:bold;padding:6px 10px;background:#fff0f0;border-left:4px solid #c00;margin-bottom:12px;">
                                <span class="dashicons dashicons-lock" style="vertical-align:text-bottom;"></span>
                                <?php esc_html_e('This account is LOCKED. The user cannot log in by any method.', 'wca-auth-engine'); ?>
                            </p>
                            <?php if ($lock_reason): ?>
                                <p style="font-style:italic;color:#555;margin-bottom:10px;">
                                    <strong><?php esc_html_e('Reason:', 'wca-auth-engine'); ?></strong>
                                    <?php echo esc_html($lock_reason); ?>
                                </p>
                            <?php endif; ?>
                            <label>
                                <input type="checkbox" name="wca_unlock_account" id="wca_unlock_account" value="1">
                                <?php esc_html_e('Unlock this account and restore login access.', 'wca-auth-engine'); ?>
                            </label>
                        <?php else: ?>
                            <label>
                                <input type="checkbox" name="wca_lock_account" id="wca_lock_account" value="1">
                                <?php esc_html_e('Lock this account (user will be unable to log in by any method).', 'wca-auth-engine'); ?>
                            </label>
                            <p class="description" style="margin-top:8px;">
                                <?php esc_html_e('Reason for lock (optional - visible to admins only):', 'wca-auth-engine'); ?>
                            </p>
                            <input type="text" name="wca_lock_reason" id="wca_lock_reason" class="regular-text" maxlength="255"
                                placeholder="<?php esc_attr_e('e.g. Suspected fraud, pending review', 'wca-auth-engine'); ?>">
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>

        </table>
        <?php
    }

    // --- Save -------------------------------------------------------------

    public static function save_fields(int $user_id): void
    {
        // 1. CSRF - bail if nonce is missing or invalid.
        if (
            !isset($_POST['wca_user_tools_nonce'])
            || !wp_verify_nonce(
                sanitize_key(wp_unslash($_POST['wca_user_tools_nonce'])),
                'wca_user_tools_' . $user_id
            )
        ) {
            return;
        }

        // 2. Capability gate.
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }

        $admin_id = get_current_user_id();

        // -- Force Re-verify ----------------------------------------------
        if (!empty($_POST['wca_force_reverify'])) {
            delete_user_meta($user_id, 'account_status');
            delete_user_meta($user_id, 'billing_phone_verified');
            update_user_meta($user_id, 'wca_needs_reverification', 1);

            WCA_Logger::log('ADMIN_FORCED_REVERIFY', [
                'user_id' => $user_id,
                'admin_id' => $admin_id,
            ]);
        }

        // -- Cancel Re-verify ---------------------------------------------
        if (!empty($_POST['wca_cancel_reverify'])) {
            update_user_meta($user_id, 'account_status', 'approved');
            delete_user_meta($user_id, 'wca_needs_reverification');

            WCA_Logger::log('ADMIN_CANCELLED_REVERIFY', [
                'user_id' => $user_id,
                'admin_id' => $admin_id,
            ]);
        }

        // -- Lock Account -------------------------------------------------
        if (!empty($_POST['wca_lock_account'])) {

            // Privilege escalation guard.
            if (user_can($user_id, 'manage_options') && !current_user_can('manage_options')) {
                return;
            }

            $reason = substr(
                sanitize_text_field(wp_unslash($_POST['wca_lock_reason'] ?? '')),
                0,
                255
            );

            update_user_meta($user_id, self::LOCK_META_KEY, 1);

            if ($reason !== '') {
                update_user_meta($user_id, self::LOCK_REASON_KEY, $reason);
            } else {
                delete_user_meta($user_id, self::LOCK_REASON_KEY);
            }

            // Immediately destroy all active WordPress sessions for this user.
            WP_Session_Tokens::get_instance($user_id)->destroy_all();

            WCA_Logger::log('ADMIN_ACCOUNT_LOCKED', [
                'user_id' => $user_id,
                'admin_id' => $admin_id,
                'reason' => $reason ?: 'none',
            ]);
        }

        // -- Unlock Account -----------------------------------------------
        if (!empty($_POST['wca_unlock_account'])) {
            delete_user_meta($user_id, self::LOCK_META_KEY);
            delete_user_meta($user_id, self::LOCK_REASON_KEY);

            WCA_Logger::log('ADMIN_ACCOUNT_UNLOCKED', [
                'user_id' => $user_id,
                'admin_id' => $admin_id,
            ]);
        }
    }

    // --- Users List: Row Action Links -------------------------------------

    /**
     * Injects "Lock Account" or "Unlock Account" into the user row actions
     * on the Users list table.
     */
    public static function add_row_actions(array $actions, WP_User $user): array
    {
        // Don't show on your own account.
        if ($user->ID === get_current_user_id()) {
            return $actions;
        }

        // Privilege escalation guard.
        $target_is_admin = user_can($user->ID, 'manage_options') || is_super_admin($user->ID);
        $current_is_admin = current_user_can('manage_options') || is_super_admin();
        if ($target_is_admin && !$current_is_admin) {
            return $actions;
        }

        $is_locked = (bool) get_user_meta($user->ID, self::LOCK_META_KEY, true);

        $redirect_to = is_network_admin() ? network_admin_url('users.php') : admin_url('users.php');

        if ($is_locked) {
            $url = add_query_arg([
                'action' => 'wca_unlock_user',
                'user_id' => $user->ID,
                '_wpnonce' => wp_create_nonce('wca_unlock_user_' . $user->ID),
                'redirect_to' => rawurlencode($redirect_to),
            ], admin_url('admin-post.php'));

            $actions['wca_unlock'] = sprintf(
                '<a href="%s" style="color:#0a7a0a;font-weight:600;" title="%s"><span class="dashicons dashicons-unlock" style="vertical-align:middle;font-size:16px;"></span> %s</a>',
                esc_url($url),
                esc_attr__('Unlock this account', 'wca-auth-engine'),
                esc_html__('Unlock Account', 'wca-auth-engine')
            );
        } else {
            $url = add_query_arg([
                'action' => 'wca_lock_user',
                'user_id' => $user->ID,
                '_wpnonce' => wp_create_nonce('wca_lock_user_' . $user->ID),
                'redirect_to' => rawurlencode($redirect_to),
            ], admin_url('admin-post.php'));

            $actions['wca_lock'] = sprintf(
                '<a href="%s" style="color:#c00;font-weight:600;" title="%s"><span class="dashicons dashicons-lock" style="vertical-align:middle;font-size:16px;"></span> %s</a>',
                esc_url($url),
                esc_attr__('Lock this account', 'wca-auth-engine'),
                esc_html__('Lock Account', 'wca-auth-engine')
            );
        }

        return $actions;
    }

    // --- Quick Lock Handler (admin-post) ----------------------------------

    public static function handle_quick_lock(): void
    {
        $user_id = absint($_GET['user_id'] ?? 0);

        if (
            !$user_id
            || !isset($_GET['_wpnonce'])
            || !wp_verify_nonce(sanitize_key($_GET['_wpnonce']), 'wca_lock_user_' . $user_id)
            || !current_user_can('edit_user', $user_id)
            || (user_can($user_id, 'manage_options') && !current_user_can('manage_options'))
        ) {
            wp_die(__('You are not allowed to perform this action.', 'wca-auth-engine'), 403);
        }

        update_user_meta($user_id, self::LOCK_META_KEY, 1);
        WP_Session_Tokens::get_instance($user_id)->destroy_all();

        WCA_Logger::log('ADMIN_ACCOUNT_LOCKED', [
            'user_id' => $user_id,
            'admin_id' => get_current_user_id(),
            'reason' => 'quick-lock via users list',
        ]);

        $redirect_to = wp_get_referer() ?: admin_url('users.php');
        $redirect_to = remove_query_arg(['wca_notice', 'wca_uid', 'wca_bulk_action', 'wca_bulk_count'], $redirect_to);

        wp_safe_redirect(add_query_arg(
            ['wca_notice' => 'locked', 'wca_uid' => $user_id],
            $redirect_to
        ));
        exit;
    }

    // --- Quick Unlock Handler (admin-post) --------------------------------

    public static function handle_quick_unlock(): void
    {
        $user_id = absint($_GET['user_id'] ?? 0);

        if (
            !$user_id
            || !isset($_GET['_wpnonce'])
            || !wp_verify_nonce(sanitize_key($_GET['_wpnonce']), 'wca_unlock_user_' . $user_id)
            || !current_user_can('edit_user', $user_id)
        ) {
            wp_die(__('You are not allowed to perform this action.', 'wca-auth-engine'), 403);
        }

        delete_user_meta($user_id, self::LOCK_META_KEY);
        delete_user_meta($user_id, self::LOCK_REASON_KEY);

        WCA_Logger::log('ADMIN_ACCOUNT_UNLOCKED', [
            'user_id' => $user_id,
            'admin_id' => get_current_user_id(),
        ]);

        $redirect = !empty($_GET['redirect_to']) ? esc_url_raw(rawurldecode($_GET['redirect_to'])) : wp_get_referer();
        $redirect = remove_query_arg(['wca_notice', 'wca_uid', 'wca_bulk_action', 'wca_bulk_count'], $redirect ?: admin_url('users.php'));

        wp_safe_redirect(add_query_arg(
            ['wca_notice' => 'unlocked', 'wca_uid' => $user_id],
            $redirect
        ));
        exit;
    }

    // --- Shared Messages ------------------------------------------------------

    public static function get_suspension_message(int $user_id = 0): string
    {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        $reason = '';
        if ($user_id) {
            $reason = get_user_meta($user_id, self::LOCK_REASON_KEY, true);
        }

        $terms_id = function_exists('wc_get_page_id') ? wc_get_page_id('terms') : 0;
        $terms_link = $terms_id ? get_permalink($terms_id) : home_url('/terms');

        $email = get_option('admin_email');
        $phone = get_option('woocommerce_store_phone') ?: get_option('store_phone', '[Support Phone Number]');

        $template = get_site_option('wca_lock_message_template', 'Your account has been locked for violating our Terms & Conditions ({REASON}). To lift the suspension, reach out to our support team at {PHONE} or {EMAIL}.');

        $message = str_replace(
            ['{REASON}', '{PHONE}', '{EMAIL}', '{TERMS_LINK}'],
            [$reason ?: __('unspecified reason', 'wca-auth-engine'), $phone, $email, $terms_link],
            $template
        );

        return trim($message);
    }

    // --- Bulk Actions ---------------------------------------------------------

    public static function register_bulk_actions(array $bulk_actions): array
    {
        $bulk_actions['wca_lock'] = __('Lock Accounts', 'wca-auth-engine');
        $bulk_actions['wca_unlock'] = __('Unlock Accounts', 'wca-auth-engine');
        return $bulk_actions;
    }

    public static function handle_bulk_actions(string $redirect_to, string $doaction, array $user_ids): string
    {
        if ($doaction !== 'wca_lock' && $doaction !== 'wca_unlock') {
            return $redirect_to;
        }

        $current_user_id = get_current_user_id();
        $changed = 0;

        foreach ($user_ids as $user_id) {
            $user_id = (int) $user_id;

            // Don't modify yourself
            if ($user_id === $current_user_id) {
                continue;
            }

            if (!current_user_can('edit_user', $user_id)) {
                continue;
            }

            // Privilege escalation guard
            if ($doaction === 'wca_lock' && user_can($user_id, 'manage_options') && !current_user_can('manage_options')) {
                continue;
            }

            if ($doaction === 'wca_lock') {
                update_user_meta($user_id, self::LOCK_META_KEY, 1);
                WP_Session_Tokens::get_instance($user_id)->destroy_all();
                WCA_Logger::log('ADMIN_ACCOUNT_LOCKED', [
                    'user_id' => $user_id,
                    'admin_id' => $current_user_id,
                    'reason' => 'bulk lock',
                ]);
            } else {
                delete_user_meta($user_id, self::LOCK_META_KEY);
                delete_user_meta($user_id, self::LOCK_REASON_KEY);
                WCA_Logger::log('ADMIN_ACCOUNT_UNLOCKED', [
                    'user_id' => $user_id,
                    'admin_id' => $current_user_id,
                    'reason' => 'bulk unlock',
                ]);
            }
            $changed++;
        }

        $redirect_to = add_query_arg('wca_bulk_action', $doaction, $redirect_to);
        $redirect_to = add_query_arg('wca_bulk_count', $changed, $redirect_to);

        return $redirect_to;
    }

    public static function admin_notices(): void
    {
        if (!isset($_GET['wca_bulk_action'], $_GET['wca_bulk_count'])) {
            return;
        }

        $action = sanitize_text_field($_GET['wca_bulk_action']);
        $count = absint($_GET['wca_bulk_count']);

        if ($action === 'wca_lock') {
            $message = sprintf(_n('%s user locked.', '%s users locked.', $count, 'wca-auth-engine'), number_format_i18n($count));
        } elseif ($action === 'wca_unlock') {
            $message = sprintf(_n('%s user unlocked.', '%s users unlocked.', $count, 'wca-auth-engine'), number_format_i18n($count));
        } else {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}

