<?php
defined('ABSPATH') || exit;

/**
 * WCA_Logger - GDPR-safe structured JSON logger.
 *
 * Rules:
 *  - NEVER logs raw email, phone, IP, password, or OTP code.
 *  - All PII is masked before writing to disk.
 *  - One JSON object per line (NDJSON format - easy to parse, tail, grep).
 *  - All writes are append-only; log rotation is handled by the Janitor cron.
 *
 * Log path: WP_CONTENT_DIR/uploads/logs/auth_engine.log
 */
class WCA_Logger
{

    // --- Allowed event types ---------------------------------------------

    private const ALLOWED_EVENTS = [
        'REGISTRATION_INITIATED',
        'EMAIL_VERIFIED',
        'SMS_VERIFIED',
        'USER_CREATED',
        'AUTH_SUCCESS',
        'AUTH_FAILED',
        'OTP_SENT',
        'OTP_RATE_LIMITED',
        'OTP_MAX_ATTEMPTS',
        'EMAIL_SEND_FAILED',
        'SMS_SEND_FAILED',
        'SMS_SENT',
        'SMS_CONFIG_MISSING',
        'EMAIL_CONFIG_MISSING',
        'SMS_ENCODING_REJECTED',
        'RECAPTCHA_FAILED',
        'CHECKOUT_ACCESS_BLOCKED',
        'PROFILE_UPDATE_INITIATED',
        'PROFILE_UPDATE_COMMITTED',
        'MIGRATION_FLAGGED',
        'MIGRATION_COMPLETE',
        'OTP_SEND_WARNING',
        'TRANSIENT_STORE_FAILED',
        'REGISTRATION_DROPPED',
        'LOGIN_SESSION_DROPPED',
        'PROFILE_SESSION_DROPPED',
        'LOG_CLEARED',
        // Fix #6: Admin tool events and password reset events were silently dropped.
        'ADMIN_FORCED_REVERIFY',
        'ADMIN_CANCELLED_REVERIFY',
        'ADMIN_ACCOUNT_LOCKED',
        'ADMIN_ACCOUNT_UNLOCKED',
        'AUTH_BLOCKED_LOCKED',
        'PASSWORD_RESET_REQUESTED',
        'PASSWORD_RESET_COMPLETED',
        'PHONE_ADDED',
        'EMAIL_SENDER_FALLBACK',
        // Fix: these two were already being logged by WCA_Account_Migrator but were
        // missing from this allowlist, so every call was silently dropped.
        'STALE_FLAG_REPAIRED',
        'STALE_FLAG_REPAIR_COMPLETE',
        'BYPASS_ACCOUNT_DELETED',
        'EMAIL_SENT',
        'DEBUG_SENDER_RESOLUTION',
        // Rate Limiter & Circuit Breaker Events
        'SMS_GLOBAL_CIRCUIT_BREAKER',
        'EMAIL_GLOBAL_CIRCUIT_BREAKER',
        'SMS_PHONE_RATE_LIMITED',
        'SMS_DISABLED',
        'SMS_BLOCKED_NO_CONTROLS',
        'SMS_COUNTRY_BLOCKED',
        'EMAIL_RECIPIENT_RATE_LIMITED',
        'REGISTRATION_COUNTRY_BLOCKED',
        'RECAPTCHA_NOT_CONFIGURED',
        'RECAPTCHA_UNREACHABLE',
        'REVERIFY_FLAG_CHECK',
        'STALE_REVERIFY_FLAG_CLEARED',
    ];

    // --- Main log method -------------------------------------------------

    /**
     * Write a structured log entry.
     *
     * @param string $event  One of the ALLOWED_EVENTS constants.
     * @param array  $data   Contextual data. Must NOT contain raw PII.
     */
    public static function log(string $event, array $data = []): void
    {
        // Validate event type.
        if (!in_array($event, self::ALLOWED_EVENTS, true)) {
            return; // Silently drop unknown events.
        }

        $entry = array_merge(
            [
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'event' => $event,
                'blog_id' => get_current_blog_id(),
            ],
            $data
        );

        // Final PII scrub - strip any field that looks like it contains raw PII.
        $entry = self::scrub_pii($entry);

        $line = wp_json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";

        self::write($line);
    }

    // --- Masking helpers (public - used by other classes) ----------------

    /**
     * Mask an email address: malik@example.com  m***@e***.com
     */
    public static function mask_email(string $email): string
    {
        if (empty($email) || !str_contains($email, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $email, 2);
        $masked_local = ($local[0] ?? '') . str_repeat('*', max(1, strlen($local) - 1));

        $domain_parts = explode('.', $domain);
        $tld = array_pop($domain_parts);
        $masked_domain = ($domain_parts[0][0] ?? '') . str_repeat('*', max(1, strlen($domain_parts[0] ?? '') - 1)) . '.' . $tld;

        return $masked_local . '@' . $masked_domain;
    }

    /**
     * Mask a phone number: +447911123456  +44******3456
     */
    public static function mask_phone(string $phone): string
    {
        if (empty($phone)) {
            return '***';
        }

        $len = strlen($phone);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }

        return substr($phone, 0, 3) . str_repeat('*', $len - 7) . substr($phone, -4);
    }

    // --- PII scrubber -----------------------------------------------------

    /**
     * Walk the log entry array and mask any keys that could contain raw PII.
     */
    private static function scrub_pii(array $entry): array
    {
        $forbidden_keys = [
            'password',
            'pass',
            'token',
            'otp',
            'otp_code',
            'otp_hash',
            'raw_phone',
            'raw_email',
            'email',
            'phone',
        ];

        foreach ($entry as $key => $value) {
            if (in_array(strtolower($key), $forbidden_keys, true)) {
                $entry[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $entry[$key] = self::scrub_pii($value);
            }
        }

        return $entry;
    }

    // --- File write -------------------------------------------------------

    private static function write(string $line): void
    {
        $path = WCA_Constants::log_path();
        $dir = dirname($path);

        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        // Append-only - locking prevents corruption under concurrent requests.
        $fp = fopen($path, 'a');
        if ($fp === false) {
            return;
        }

        flock($fp, LOCK_EX);
        fwrite($fp, $line);
        flock($fp, LOCK_UN);
        fclose($fp);

        // Cap log size at 5MB - trim the oldest lines if over limit.
        if (file_exists($path) && filesize($path) > 5 * 1024 * 1024) {
            self::trim_log($path);
        }
    }

    // --- Log trimmer - keep most recent 1000 lines -----------------------

    private static function trim_log(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_slice($lines, -1000);
        file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX);
    }

    // --- GDPR Log Cleanup (Cron) ------------------------------------------

    /**
     * Delete log entries older than 30 days.
     * To be run via a daily cron job.
     */
    public static function cleanup_old_logs(): void
    {
        $path = WCA_Constants::log_path();
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return;
        }

        $cutoff_time = strtotime('-30 days');
        $new_lines = [];
        $deleted = 0;

        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (isset($data['timestamp']) && strtotime($data['timestamp']) >= $cutoff_time) {
                $new_lines[] = $line;
            } else {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            file_put_contents($path, implode("\n", $new_lines) . "\n", LOCK_EX);
            self::log('LOG_CLEARED', ['deleted_entries' => $deleted, 'reason' => 'gdpr_30_day_retention']);
        }
    }
}
