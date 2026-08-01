<?php
defined('ABSPATH') || exit;

/**
 * WCA_Network_Admin - Registers the network admin menu and page controller.
 */
class WCA_Network_Admin
{

    public static function register_menu(): void
    {
        add_menu_page(
            __('WCA Auth Engine', 'wca-auth-engine'),
            __('WCA Auth', 'wca-auth-engine'),
            'manage_network_options',
            'wca-auth-engine',
            [self::class, 'render_page'],
            'dashicons-shield-alt',
            80
        );

        add_submenu_page(
            'wca-auth-engine',
            __('Settings', 'wca-auth-engine'),
            __('Settings', 'wca-auth-engine'),
            'manage_network_options',
            'wca-auth-engine',
            [self::class, 'render_page']
        );

        add_submenu_page(
            'wca-auth-engine',
            __('Log Viewer', 'wca-auth-engine'),
            __('Log Viewer', 'wca-auth-engine'),
            'manage_network_options',
            'wca-auth-engine-logs',
            ['WCA_Log_Viewer', 'render']
        );

        add_submenu_page(
            'wca-auth-engine',
            __('Migration', 'wca-auth-engine'),
            __('Migration', 'wca-auth-engine'),
            'manage_network_options',
            'wca-auth-engine-migration',
            [self::class, 'render_migration_page']
        );
    }

    // --- Main settings page -----------------------------------------------

    private const TABS = [
        'general' => 'General',
        'emails' => 'Email Templates',
        'login_notify' => 'Login Notify',
    ];

    public static function render_page(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(__('You do not have permission to access this page.', 'wca-auth-engine'));
        }

        WCA_Settings::handle_save();

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        if (!isset(self::TABS[$tab])) {
            $tab = 'general';
        }
        ?>
        <div class="wrap wca-admin-wrap">
            <h1><?php esc_html_e('WCA Auth Engine - Settings', 'wca-auth-engine'); ?></h1>
            <?php settings_errors('wca_settings'); ?>
            <?php settings_errors('wca_login_notify'); ?>
            <?php WCA_Settings::render_protection_notice(); ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach (self::TABS as $tab_key => $tab_label):
                    $url = add_query_arg(['page' => 'wca-auth-engine', 'tab' => $tab_key], network_admin_url('admin.php'));
                    ?>
                    <a href="<?php echo esc_url($url); ?>"
                        class="nav-tab <?php echo $tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab_label); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <div class="wca-admin-tab-content" style="margin-top:20px;">
                <?php if ($tab === 'login_notify'): ?>
                    <?php WCA_Settings::render_form($tab); ?>
                    <hr style="margin:32px 0 24px;border:none;border-top:1px solid #dcdcde;">
                    <?php WCA_Login_Notify::render_admin_panel(); ?>
                <?php else: ?>
                    <?php WCA_Settings::render_form($tab); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // --- Migration page ---------------------------------------------------

    public static function render_migration_page(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(__('You do not have permission to access this page.', 'wca-auth-engine'));
        }

        // Handle the migration trigger.
        if (
            isset($_POST['wca_run_migration']) &&
            check_admin_referer('wca_migration_nonce', 'wca_migration_nonce_field')
        ) {
            if (!WCA_Constants::migration_run()) {
                WCA_Account_Migrator::schedule();
                add_settings_error('wca_migration', 'scheduled', __('Migration has been scheduled. It will run in the background within 30 seconds.', 'wca-auth-engine'), 'updated');
            }
        }

        // Handle the repair trigger.
        if (
            isset($_POST['wca_run_repair']) &&
            check_admin_referer('wca_repair_nonce', 'wca_repair_nonce_field')
        ) {
            WCA_Account_Migrator::schedule_repair();
            add_settings_error('wca_repair', 'scheduled', __('Stale flag repair has been scheduled. It will run in the background within 30 seconds. Refresh this page to see results.', 'wca-auth-engine'), 'updated');
        }

        $migration_run = WCA_Constants::migration_run();
        $flagged_count = get_site_option('wca_migration_flagged_count', '-');
        $migration_run_at = get_site_option('wca_migration_run_at', '');

        $repair_run = get_site_option('wca_repair_run', false);
        $repair_run_at = get_site_option('wca_repair_run_at', '');
        $repair_scanned = get_site_option('wca_repair_scanned_count', '-');
        $repair_fixed = get_site_option('wca_repair_fixed_count', '-');

        // -- Dry-run scan (read-only, no changes) -----------------------------
        $scan_results = [];
        $run_scan = isset($_POST['wca_run_scan']) &&
            check_admin_referer('wca_scan_nonce', 'wca_scan_nonce_field');

        if ($run_scan) {
            global $wpdb;

            // On standard WP multisite, wp_users and wp_usermeta are GLOBAL tables -
            // switch_to_blog() does not change them. So we run the stale/genuine queries
            // once globally, then break the count down per site using capabilities meta keys.

            // Global: users with stale flag (verified but flag stuck).
            $stale_rows_global = $wpdb->get_col("
                SELECT rv.user_id
                FROM {$wpdb->usermeta} rv
                INNER JOIN {$wpdb->usermeta} ev
                    ON rv.user_id = ev.user_id
                    AND ev.meta_key = 'billing_email_verified'
                    AND ev.meta_value = '1'
                INNER JOIN {$wpdb->usermeta} pv
                    ON rv.user_id = pv.user_id
                    AND pv.meta_key = 'billing_phone_verified'
                    AND pv.meta_value = '1'
                WHERE rv.meta_key  = 'wca_needs_reverification'
                  AND rv.meta_value = '1'
            ");

            // Global: users with flag who are NOT yet fully verified.
            $genuine_global = (int) $wpdb->get_var("
                SELECT COUNT(DISTINCT rv.user_id)
                FROM {$wpdb->usermeta} rv
                LEFT JOIN {$wpdb->usermeta} ev
                    ON rv.user_id = ev.user_id
                    AND ev.meta_key = 'billing_email_verified'
                    AND ev.meta_value = '1'
                LEFT JOIN {$wpdb->usermeta} pv
                    ON rv.user_id = pv.user_id
                    AND pv.meta_key = 'billing_phone_verified'
                    AND pv.meta_value = '1'
                WHERE rv.meta_key  = 'wca_needs_reverification'
                  AND rv.meta_value = '1'
                  AND ( ev.meta_value IS NULL OR pv.meta_value IS NULL )
            ");

            $stale_ids_set = array_flip($stale_rows_global); // for fast lookup
            $sites = is_multisite() ? get_sites(['number' => 9999]) : [null];

            foreach ($sites as $site) {
                $blog_id = is_multisite() && $site ? (int) $site->blog_id : get_current_blog_id();
                $blog_name = is_multisite() && $site ? get_blog_details($blog_id)->blogname : get_bloginfo('name');

                // Count members of THIS specific site using their capabilities key.
                $cap_key = $wpdb->get_blog_prefix($blog_id) . 'capabilities';
                $total_users = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s",
                    $cap_key
                ));

                // Which stale users are members of this site?
                $site_stale_ids = [];
                if (!empty($stale_rows_global)) {
                    $ids_in = implode(',', array_map('intval', $stale_rows_global));
                    $site_stale_ids = $wpdb->get_col($wpdb->prepare(
                        "SELECT user_id FROM {$wpdb->usermeta}
                         WHERE meta_key = %s AND user_id IN ({$ids_in})",
                        $cap_key
                    ));
                }

                $scan_results[] = [
                    'blog_id' => $blog_id,
                    'blog_name' => $blog_name,
                    'total_users' => $total_users,
                    'stale_count' => count($site_stale_ids),
                    'sample_ids' => array_slice($site_stale_ids, 0, 20),
                    'genuine_count' => '', // per-site breakdown for genuine is complex; shown in global total only
                ];
            }

            // Attach global genuine count to summary row only.
            $scan_genuine_global = $genuine_global;
        }


        $repair_run = get_site_option('wca_repair_run', false);
        $repair_run_at = get_site_option('wca_repair_run_at', '');
        $repair_scanned = get_site_option('wca_repair_scanned_count', '-');
        $repair_fixed = get_site_option('wca_repair_fixed_count', '-');
        ?>

        <!-- -- Repair Stale Flags ------------------------------------ -->
        <div class="wca-migration-panel card" style="max-width:780px;padding:20px;">
            <h2><?php esc_html_e('Repair Stale Reverification Flags', 'wca-auth-engine'); ?></h2>
            <p><?php esc_html_e('Some legacy accounts have wca_needs_reverification = 1 even though their email and phone are both verified - causing an endless checkout redirect loop for those users.', 'wca-auth-engine'); ?>
            </p>
            <p><?php esc_html_e('Step 1: Run the dry-run scan to see exactly which accounts are affected per site. No changes are made. Step 2: Once satisfied, click Repair to fix them all.', 'wca-auth-engine'); ?>
            </p>

            <?php if ($repair_run): ?>
                <div class="notice notice-success inline" style="margin-bottom:16px;">
                    <p>
                        <?php printf(
                            esc_html__('Last repair: %1$s - Scanned: %2$s - Fixed: %3$s accounts.', 'wca-auth-engine'),
                            esc_html($repair_run_at),
                            esc_html($repair_scanned),
                            '<strong>' . esc_html($repair_fixed) . '</strong>'
                        ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Step 1: Dry-run scan -->
            <form method="post" style="margin-bottom:16px;">
                <?php wp_nonce_field('wca_scan_nonce', 'wca_scan_nonce_field'); ?>
                <input type="submit" name="wca_run_scan" class="button button-secondary"
                    value="<?php esc_attr_e(' Scan All Sites (Dry Run - No Changes)', 'wca-auth-engine'); ?>">
                <p class="description" style="margin-top:6px;">
                    <?php esc_html_e('Read-only scan. Shows a breakdown per site of how many accounts have a stale flag and how many genuinely need reverification. Safe to run anytime.', 'wca-auth-engine'); ?>
                </p>
            </form>

            <?php if ($run_scan && !empty($scan_results)):
                $total_stale = array_sum(array_column($scan_results, 'stale_count'));
                $total_genuine = $scan_genuine_global ?? 0;  // global count - accurate for shared usermeta
                $total_users = array_sum(array_column($scan_results, 'total_users'));
                ?>
                <h3 style="margin-top:8px;"><?php esc_html_e('Scan Results', 'wca-auth-engine'); ?></h3>
                <table class="widefat striped" style="margin-bottom:20px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Site', 'wca-auth-engine'); ?></th>
                            <th><?php esc_html_e('Total Users', 'wca-auth-engine'); ?></th>
                            <th style="color:#b86000"><?php esc_html_e(' Stale Flag (will be repaired)', 'wca-auth-engine'); ?>
                            </th>
                            <th style="color:#0a7a0a"><?php esc_html_e(' Genuinely Needs Reverify', 'wca-auth-engine'); ?></th>
                            <th><?php esc_html_e('Sample User IDs', 'wca-auth-engine'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scan_results as $row): ?>
                            <tr>
                                <td><strong><?php echo esc_html($row['blog_name']); ?></strong><br>
                                    <small style="color:#999">ID: <?php echo esc_html($row['blog_id']); ?></small>
                                </td>
                                <td><?php echo esc_html(number_format($row['total_users'])); ?></td>
                                <td>
                                    <strong style="color:<?php echo $row['stale_count'] > 0 ? '#b86000' : '#0a7a0a'; ?>">
                                        <?php echo esc_html($row['stale_count']); ?>
                                    </strong>
                                </td>
                                <td style="color:#888;font-size:0.85em;">
                                    <?php esc_html_e('(see TOTAL)', 'wca-auth-engine'); ?>
                                </td>
                                <td style="font-size:0.8em;color:#555;max-width:200px;word-break:break-all;">
                                    <?php echo $row['stale_count'] > 0
                                        ? esc_html(implode(', ', $row['sample_ids']))
                                        . (count($row['sample_ids']) >= 20 ? '  (20 shown)' : '')
                                        : '-';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:bold;background:#f6f7f7;">
                            <td><?php esc_html_e('TOTAL', 'wca-auth-engine'); ?></td>
                            <td><?php echo esc_html(number_format($total_users)); ?></td>
                            <td style="color:#b86000"><?php echo esc_html($total_stale); ?></td>
                            <td style="color:#dc2626"><?php echo esc_html($total_genuine); ?></td>
                            <td>-</td>
                        </tr>
                    </tfoot>
                </table>

                <?php if ($total_stale > 0): ?>
                    <div class="notice notice-warning inline" style="margin-bottom:12px;">
                        <p>
                            <?php printf(
                                esc_html__('Found %d accounts across all sites with a stale reverification flag. Click "Repair Now" below to clear them all. %d accounts genuinely still need to verify their email or phone and will NOT be touched.', 'wca-auth-engine'),
                                $total_stale,
                                $total_genuine
                            ); ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="notice notice-success inline" style="margin-bottom:12px;">
                        <p>
                            <?php esc_html_e(' No stale flags found across any site. All users with the reverification flag genuinely need to verify their contact details.', 'wca-auth-engine'); ?>
                        </p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Step 2: Repair (only shown after scan, or always visible if repair was previously run) -->
            <?php if ($run_scan || $repair_run):
                $stale_total_for_btn = $run_scan ? ($total_stale ?? 0) : (int) $repair_fixed;
                ?>
                <form method="post" style="border-top:1px solid #ddd;padding-top:16px;margin-top:8px;">
                    <?php wp_nonce_field('wca_repair_nonce', 'wca_repair_nonce_field'); ?>
                    <input type="submit" name="wca_run_repair" class="button button-primary" <?php echo ($run_scan && ($total_stale ?? 0) === 0) ? 'disabled' : ''; ?> value="<?php echo $run_scan && isset($total_stale) && $total_stale > 0
                                          ? esc_attr(sprintf(__(' Repair %d Stale Accounts Now', 'wca-auth-engine'), $total_stale))
                                          : esc_attr__('Run Repair Across All Sites', 'wca-auth-engine'); ?>">
                    <p class="description" style="margin-top:8px;">
                        <?php esc_html_e('Runs via WP-Cron in the background. Refresh this page after ~30 seconds to see results. Safe to run multiple times.', 'wca-auth-engine'); ?>
                    </p>
                </form>
            <?php endif; ?>

        </div>
        </div>
        <?php
    }
}
