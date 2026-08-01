<?php
defined('ABSPATH') || exit;

/**
 * WCA_User_Table_Columns - Adds account_status and billing_phone columns to
 * the WP admin Users list table, and replaces the core email column with a
 * verified-aware one.
 */
class WCA_User_Table_Columns
{

    public static function add_column(array $columns): array
    {
        // Splice our verified-aware email column in at the exact position the
        // core 'email' column occupied, instead of appending at the end.
        $rebuilt = [];
        $replaced_email = false;

        foreach ($columns as $key => $label) {
            if ($key === 'email') {
                $rebuilt['wca_email'] = __('Email (Verified)', 'wca-auth-engine');
                $replaced_email = true;
                continue;
            }
            $rebuilt[$key] = $label;
        }

        if (!$replaced_email) {
            $rebuilt['wca_email'] = __('Email (Verified)', 'wca-auth-engine');
        }

        $rebuilt['wca_account_status'] = __('Account Status', 'wca-auth-engine');
        $rebuilt['wca_phone'] = __('Phone (Verified)', 'wca-auth-engine');

        return $rebuilt;
    }

    public static function init(): void
    {
        add_filter('manage_users_sortable_columns', [self::class, 'register_sortable_columns']);
        add_action('pre_get_users', [self::class, 'handle_sorting']);

        if (is_multisite()) {
            add_filter('manage_users-network_sortable_columns', [self::class, 'register_sortable_columns']);
        }
    }

    public static function register_sortable_columns(array $columns): array
    {
        $columns['wca_account_status'] = 'wca_account_status';
        // 'email' is a native WP_User_Query orderby alias (maps to user_email) -
        // no custom meta_query handling needed, unlike wca_account_status above.
        $columns['wca_email'] = 'email';
        return $columns;
    }

    public static function handle_sorting(WP_User_Query $query): void
    {
        if (!is_admin() || !function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if ($screen && $screen->id === 'users' && $query->get('orderby') === 'wca_account_status') {
            // Sort by the wca_account_locked meta key first. We use meta_query to pull it in.
            $query->set('meta_key', 'wca_account_locked');
            $query->set('orderby', 'meta_value_num');
        }
    }

    public static function render_column(string $output, string $column_name, int $user_id): string
    {
        if ($column_name === 'wca_account_status') {
            $status = get_user_meta($user_id, 'account_status', true);
            $needs = get_user_meta($user_id, 'wca_needs_reverification', true);
            $locked = get_user_meta($user_id, WCA_Admin_User_Tools::LOCK_META_KEY, true);

            if ($locked) {
                return '<span style="color:#c00;font-weight:bold;"><span class="dashicons dashicons-lock" style="vertical-align:text-bottom;"></span> Locked</span>';
            }

            if ($needs) {
                return '<span style="color:#c00;font-weight:bold;"><span class="dashicons dashicons-warning" style="vertical-align:text-bottom;"></span> Needs Re-Verify</span>';
            }

            return match ($status) {
                'approved' => '<span style="color:#0a7a0a;"><span class="dashicons dashicons-yes-alt" style="vertical-align:text-bottom;"></span> Approved</span>',
                'pending' => '<span style="color:#b86000;"><span class="dashicons dashicons-clock" style="vertical-align:text-bottom;"></span> Pending</span>',
                '' => '<span style="color:#999;">- Not Set</span>',
                default => '<span style="color:#c00;">' . esc_html($status) . '</span>',
            };
        }

        if ($column_name === 'wca_email') {
            $user = get_userdata($user_id);
            $email = $user ? $user->user_email : '';

            if (!$email) {
                return '<span style="color:#999;">-</span>';
            }

            $verified = get_user_meta($user_id, 'billing_email_verified', true);
            $link = '<a href="' . esc_url('mailto:' . $email) . '">' . esc_html($email) . '</a>';

            return $verified
                ? '<span style="color:#0a7a0a;"><span class="dashicons dashicons-yes-alt" style="vertical-align:text-bottom;font-size:16px;"></span> ' . $link . '</span>'
                : '<span style="color:#b86000;">' . $link . '</span>';
        }

        if ($column_name === 'wca_phone') {
            $phone = get_user_meta($user_id, 'billing_phone', true);
            $verified = get_user_meta($user_id, 'billing_phone_verified', true);

            if (!$phone) {
                return '<span style="color:#999;">-</span>';
            }

            return $verified
                ? '<span style="color:#0a7a0a;"><span class="dashicons dashicons-yes-alt" style="vertical-align:text-bottom;font-size:16px;"></span> ' . esc_html($phone) . '</span>'
                : '<span style="color:#b86000;">' . esc_html($phone) . '</span>';
        }

        return $output;
    }
}
