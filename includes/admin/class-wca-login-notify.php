<?php
defined('ABSPATH') || exit;

/**
 * WCA_Login_Notify - Per-customer, network-wide login notice.
 *
 * Data model: a single user-meta value (META_KEY) holds the HTML notice.
 * Active state = non-empty message; there is no separate enable flag.
 * wp_usermeta is a global multisite table, so the notice automatically
 * follows the customer to whichever network site they log into.
 */
class WCA_Login_Notify
{

    public const META_KEY = 'wca_login_notify_message';

    public static function init(): void
    {
        add_action('wp_ajax_wca_user_search', [self::class, 'ajax_user_search']);
        add_action('wp_ajax_wca_get_login_notify', [self::class, 'ajax_get']);
        add_action('wp_ajax_wca_save_login_notify', [self::class, 'ajax_save']);
    }

    // --- Customer-facing helpers --------------------------------------------

    public static function get_message(int $user_id): string
    {
        if (!$user_id) {
            return '';
        }
        return (string) get_user_meta($user_id, self::META_KEY, true);
    }

    /** Hooked to: woocommerce_account_dashboard */
    public static function render_dashboard_notice(): void
    {
        $user_id = get_current_user_id();
        $message = self::get_message($user_id);

        if ($message === '') {
            return;
        }
        ?>
        <div class="woocommerce-info wca-login-notify-notice">
            <?php echo wp_kses_post($message); ?>
        </div>
        <?php
    }

    // --- Admin panel --------------------------------------------------------

    public static function render_admin_panel(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(__('You do not have permission to access this page.', 'wca-auth-engine'));
        }
        ?>
        <div class="wca-login-notify-panel">
            <h2><?php esc_html_e('Login Notify', 'wca-auth-engine'); ?></h2>
            <p class="description">
                <?php esc_html_e('Write a one-time notice for a specific customer. They will see it after signing in (and on their account dashboard) until you clear it.', 'wca-auth-engine'); ?>
            </p>

            <div class="wca-notify-search">
                <label
                    for="wca-notify-user-search"><strong><?php esc_html_e('Find a customer', 'wca-auth-engine'); ?></strong></label><br>
                <input type="text" id="wca-notify-user-search" class="regular-text" autocomplete="off"
                    placeholder="<?php esc_attr_e('Search by name, username or email', 'wca-auth-engine'); ?>">
                <div id="wca-notify-search-results" class="wca-notify-search-results" hidden></div>
            </div>

            <div id="wca-notify-editor" class="card wca-notify-editor" style="display:none;">
                <p>
                    <strong id="wca-notify-selected-name"></strong>
                    &mdash; <span id="wca-notify-selected-email" style="color:#666;"></span>
                    <span id="wca-notify-selected-status" style="margin-left:10px;font-weight:600;"></span>
                </p>
                <textarea id="wca-notify-message" rows="6" class="large-text code"
                    placeholder="<?php esc_attr_e('HTML allowed. Shown to this customer after their next login.', 'wca-auth-engine'); ?>"></textarea>
                <p>
                    <button type="button" class="button button-primary"
                        id="wca-notify-save"><?php esc_html_e('Save Notice', 'wca-auth-engine'); ?></button>
                    <button type="button" class="button"
                        id="wca-notify-clear"><?php esc_html_e('Clear Notification', 'wca-auth-engine'); ?></button>
                    <span id="wca-notify-status" style="margin-left:10px;"></span>
                </p>
                <input type="hidden" id="wca-notify-user-id" value="">
            </div>

            <h3 style="margin-top:32px;"><?php esc_html_e('Active Notifications', 'wca-auth-engine'); ?></h3>
            <table class="widefat striped" id="wca-notify-active-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('User', 'wca-auth-engine'); ?></th>
                        <th><?php esc_html_e('Message', 'wca-auth-engine'); ?></th>
                        <th style="width:160px;"><?php esc_html_e('Actions', 'wca-auth-engine'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $active = self::get_active_notices();
                    if (empty($active)): ?>
                        <tr data-empty>
                            <td colspan="3"><?php esc_html_e('No active notifications.', 'wca-auth-engine'); ?></td>
                        </tr>
                    <?php else:
                        foreach ($active as $row): ?>
                            <tr data-user-id="<?php echo esc_attr($row['id']); ?>">
                                <td>
                                    <?php echo esc_html($row['name']); ?><br>
                                    <small style="color:#888;"><?php echo esc_html($row['email']); ?></small>
                                </td>
                                <td><?php echo esc_html($row['excerpt']); ?></td>
                                <td>
                                    <button type="button" class="button button-small wca-notify-row-edit"
                                        data-user-id="<?php echo esc_attr($row['id']); ?>"><?php esc_html_e('Edit', 'wca-auth-engine'); ?></button>
                                    <button type="button" class="button button-small wca-notify-row-clear"
                                        data-user-id="<?php echo esc_attr($row['id']); ?>"><?php esc_html_e('Clear', 'wca-auth-engine'); ?></button>
                                </td>
                            </tr>
                        <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function get_active_notices(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != '' ORDER BY user_id DESC LIMIT 200",
            self::META_KEY
        ));

        $out = [];
        foreach ($rows as $row) {
            $user = get_userdata((int) $row->user_id);
            if (!$user) {
                continue;
            }
            $out[] = [
                'id' => (int) $row->user_id,
                'name' => $user->display_name ?: $user->user_login,
                'email' => $user->user_email,
                'excerpt' => self::excerpt($row->meta_value),
            ];
        }

        return $out;
    }

    private static function excerpt(string $html, int $length = 80): string
    {
        $text = wp_strip_all_tags($html);
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '' : $text;
    }

    // --- AJAX: user search (autocomplete) --------------------------------

    public static function ajax_user_search(): void
    {
        ob_start();
        check_ajax_referer('wca_login_notify');

        if (!current_user_can('manage_network_options')) {
            ob_end_clean();
            wp_send_json_error(['message' => __('Permission denied.', 'wca-auth-engine')], 403);
        }

        $search = sanitize_text_field(wp_unslash($_GET['search'] ?? $_POST['search'] ?? ''));
        if (mb_strlen($search) < 2) {
            ob_end_clean();
            wp_send_json_success(['users' => []]);
        }

        $query = new WP_User_Query([
            'search'         => '*' . $search . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'number'         => 20,
            'fields'         => ['ID', 'display_name', 'user_email', 'user_login'],
        ]);

        $users = [];
        foreach ($query->get_results() as $user) {
            $message = self::get_message((int) $user->ID);
            $users[] = [
                'id'           => (int) $user->ID,
                'display_name' => $user->display_name ?: $user->user_login,
                'email'        => $user->user_email,
                'has_notice'   => $message !== '',
            ];
        }

        ob_end_clean();
        wp_send_json_success(['users' => $users]);
    }

    // --- AJAX: get a user's current notice -------------------------------

    public static function ajax_get(): void
    {
        ob_start();
        check_ajax_referer('wca_login_notify');

        if (!current_user_can('manage_network_options')) {
            ob_end_clean();
            wp_send_json_error(['message' => __('Permission denied.', 'wca-auth-engine')], 403);
        }

        $user_id = absint($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
        $user = $user_id ? get_user_by('id', $user_id) : false;

        if (!$user) {
            ob_end_clean();
            wp_send_json_error(['message' => __('User not found.', 'wca-auth-engine')], 404);
        }

        $message = self::get_message($user_id);

        ob_end_clean();
        wp_send_json_success([
            'user_id'      => $user_id,
            'display_name' => $user->display_name ?: $user->user_login,
            'email'        => $user->user_email,
            'message'      => $message,
            'active'       => $message !== '',
        ]);
    }

    // --- AJAX: save / clear -----------------------------------------------

    public static function ajax_save(): void
    {
        ob_start();
        check_ajax_referer('wca_login_notify');

        if (!current_user_can('manage_network_options')) {
            ob_end_clean();
            wp_send_json_error(['message' => __('Permission denied.', 'wca-auth-engine')], 403);
        }

        $user_id = absint($_POST['user_id'] ?? 0);
        $user = $user_id ? get_user_by('id', $user_id) : false;

        if (!$user) {
            ob_end_clean();
            wp_send_json_error(['message' => __('User not found.', 'wca-auth-engine')], 404);
        }

        $clear = !empty($_POST['clear']);
        $raw = $clear ? '' : wp_unslash($_POST['message'] ?? '');
        $message = trim(current_user_can('unfiltered_html') ? $raw : wp_kses_post($raw));

        if ($message === '') {
            delete_user_meta($user_id, self::META_KEY);
        } else {
            update_user_meta($user_id, self::META_KEY, $message);
        }

        ob_end_clean();
        wp_send_json_success([
            'user_id' => $user_id,
            'message' => $message,
            'active'  => $message !== '',
        ]);
    }
}
