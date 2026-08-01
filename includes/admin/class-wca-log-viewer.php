<?php
defined('ABSPATH') || exit;

/**
 * WCA_Log_Viewer - Reads and renders auth_engine.log in the network admin UI.
 */
class WCA_Log_Viewer
{

    private const MAX_LINES = 200;

    public static function render(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(__('Access denied.', 'wca-auth-engine'));
        }

        // Handle clear log action.
        if (
            isset($_POST['wca_clear_log']) &&
            check_admin_referer('wca_clear_log_nonce', 'wca_clear_log_nonce_field')
        ) {
            self::clear_log();
            add_settings_error('wca_log', 'cleared', __('Log cleared.', 'wca-auth-engine'), 'updated');
        }

        // Handle CSV export.
        if (isset($_GET['wca_export_log'])) {
            self::export_csv();
        }

        $entries = self::read_log();
        $filter_evt = sanitize_text_field($_GET['wca_event'] ?? '');
        $filter_date = sanitize_text_field($_GET['wca_date'] ?? '');

        // Apply filters.
        if ($filter_evt || $filter_date) {
            $entries = array_filter($entries, function ($e) use ($filter_evt, $filter_date) {
                if ($filter_evt && ($e['event'] ?? '') !== $filter_evt)
                    return false;
                if ($filter_date && !str_starts_with($e['timestamp'] ?? '', $filter_date))
                    return false;
                return true;
            });
        }

        // Get counts for budget display.
        $all_entries = self::read_log();
        $sms_count = self::count_event($all_entries, 'SMS_SENT') + self::count_event($all_entries, 'OTP_SENT', 'sms');
        $email_count = self::count_event($all_entries, 'EMAIL_SENT') + self::count_event($all_entries, 'OTP_SENT', 'email');
        $unique_events = array_unique(array_column($all_entries, 'event'));

        ?>
        <div class="wrap wca-admin-wrap">
            <h1><?php esc_html_e('WCA Auth Engine - Log Viewer', 'wca-auth-engine'); ?></h1>
            <?php settings_errors('wca_log'); ?>

            <!-- Budget summary -->
            <div class="wca-budget-bar" style="display:flex;gap:20px;margin-bottom:20px;">
                <div class="card" style="padding:15px;min-width:180px;">
                    <strong><?php esc_html_e('SMS Sent (this log)', 'wca-auth-engine'); ?></strong><br>
                    <span style="font-size:2em;color:<?php echo $sms_count > 15000 ? '#c00' : '#0a7a0a'; ?>">
                        <?php echo esc_html(number_format($sms_count)); ?>
                    </span>
                    <small> / 20,000 monthly cap</small>
                </div>
                <div class="card" style="padding:15px;min-width:180px;">
                    <strong><?php esc_html_e('Emails Sent (this log)', 'wca-auth-engine'); ?></strong><br>
                    <span style="font-size:2em;"><?php echo esc_html(number_format($email_count)); ?></span>
                    <small> / 40,000 monthly cap</small>
                </div>
            </div>

            <!-- Filters -->
            <form method="get" style="margin-bottom:15px;">
                <input type="hidden" name="page" value="wca-auth-engine-logs">
                <select name="wca_event">
                    <option value=""><?php esc_html_e('- All Events -', 'wca-auth-engine'); ?></option>
                    <?php foreach ($unique_events as $evt): ?>
                        <option value="<?php echo esc_attr($evt); ?>" <?php selected($filter_evt, $evt); ?>>
                            <?php echo esc_html($evt); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="wca_date" value="<?php echo esc_attr($filter_date); ?>">
                <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'wca-auth-engine'); ?>">
                <a href="<?php echo esc_url(admin_url('network/admin.php?page=wca-auth-engine-logs&wca_export_log=1')); ?>"
                    class="button"><?php esc_html_e('Export CSV', 'wca-auth-engine'); ?></a>
            </form>

            <!-- Log table -->
            <div style="overflow-x:auto;">
                <table class="widefat striped" style="font-size:12px;font-family:monospace;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Timestamp', 'wca-auth-engine'); ?></th>
                            <th><?php esc_html_e('Event', 'wca-auth-engine'); ?></th>
                            <th><?php esc_html_e('Blog', 'wca-auth-engine'); ?></th>
                            <th><?php esc_html_e('User', 'wca-auth-engine'); ?></th>
                            <th><?php esc_html_e('Details', 'wca-auth-engine'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($entries)): ?>
                            <tr>
                                <td colspan="5"><?php esc_html_e('No log entries found.', 'wca-auth-engine'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach (array_reverse($entries) as $entry): ?>
                                <tr>
                                    <td><?php echo esc_html($entry['timestamp'] ?? '-'); ?></td>
                                    <td><span
                                            class="wca-event-badge wca-event-<?php echo esc_attr(strtolower($entry['event'] ?? '')); ?>">
                                            <?php echo esc_html($entry['event'] ?? '-'); ?>
                                        </span></td>
                                    <td><?php echo esc_html($entry['blog_id'] ?? '-'); ?></td>
                                    <td><?php echo esc_html($entry['user_id'] ?? $entry['masked_email'] ?? '-'); ?></td>
                                    <td><?php
                                    $meta = $entry['meta'] ?? [];
                                    unset($entry['timestamp'], $entry['event'], $entry['blog_id'], $entry['user_id'], $entry['meta']);
                                    $all = array_merge($entry, $meta);
                                    echo esc_html(implode(' | ', array_map(
                                        fn($k, $v) => "{$k}: {$v}",
                                        array_keys($all),
                                        array_values($all)
                                    )));
                                    ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Clear log -->
            <form method="post" style="margin-top:20px;">
                <?php wp_nonce_field('wca_clear_log_nonce', 'wca_clear_log_nonce_field'); ?>
                <input type="submit" name="wca_clear_log" class="button button-secondary"
                    value="<?php esc_attr_e('Clear Log', 'wca-auth-engine'); ?>"
                    onclick="return confirm('<?php esc_attr_e('Clear the entire log? A LOG_CLEARED entry will be written first.', 'wca-auth-engine'); ?>')">
            </form>
        </div>
        <?php
    }

    // --- Read last N lines from log ---------------------------------------

    private static function read_log(): array
    {
        $path = WCA_Constants::log_path();

        if (!file_exists($path) || !is_readable($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_slice($lines, -self::MAX_LINES);
        $entries = [];

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    // --- Count events -----------------------------------------------------

    private static function count_event(array $entries, string $event, string $channel = ''): int
    {
        return count(array_filter($entries, function ($e) use ($event, $channel) {
            if (($e['event'] ?? '') !== $event)
                return false;
            if ($channel && ($e['channel'] ?? '') !== $channel)
                return false;
            return true;
        }));
    }

    // --- Clear log --------------------------------------------------------

    private static function clear_log(): void
    {
        $path = WCA_Constants::log_path();
        // Write a sentinel entry first.
        WCA_Logger::log('LOG_CLEARED', ['cleared_by_user_id' => get_current_user_id()]);
        file_put_contents($path, '');
        // Re-write the sentinel to the freshly empty file.
        WCA_Logger::log('LOG_CLEARED', ['cleared_by_user_id' => get_current_user_id()]);
    }

    // --- Export CSV -------------------------------------------------------

    private static function export_csv(): void
    {
        if (!current_user_can('manage_network_options')) {
            return;
        }

        $entries = self::read_log();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=wca-auth-log-' . date('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['timestamp', 'event', 'blog_id', 'user_id', 'ip_hash', 'masked_email', 'masked_phone', 'meta']);

        foreach ($entries as $e) {
            fputcsv($out, [
                $e['timestamp'] ?? '',
                $e['event'] ?? '',
                $e['blog_id'] ?? '',
                $e['user_id'] ?? '',
                $e['ip_hash'] ?? '',
                $e['masked_email'] ?? '',
                $e['masked_phone'] ?? '',
                wp_json_encode($e['meta'] ?? []),
            ]);
        }

        fclose($out);
        exit;
    }
}
