<?php
defined('ABSPATH') || exit;

/**
 * WCA_Constants - centralised runtime constant accessors.
 *
 * Provides typed, safe access to plugin settings stored in wp_sitemeta.
 * Caches values per request to avoid repeated DB hits.
 */
final class WCA_Constants
{

    private static array $cache = [];

    // --- Country Codes ----------------------------------------------------

    /**
     * Returns an array of supported country dial codes and their labels.
     * Can be hooked/filtered in the future to dynamically add more countries.
     */
    public static function get_country_dial_codes(): array
    {
        $codes = [
            '+93' => 'AF +93',
            '+358-18' => 'AX +358',
            '+355' => 'AL +355',
            '+213' => 'DZ +213',
            '+1-684' => 'AS +1',
            '+376' => 'AD +376',
            '+244' => 'AO +244',
            '+1-264' => 'AI +1',
            '+672' => 'AQ +672',
            '+1-268' => 'AG +1',
            '+54' => 'AR +54',
            '+374' => 'AM +374',
            '+297' => 'AW +297',
            '+61' => 'AU +61',
            '+43' => 'AT +43',
            '+994' => 'AZ +994',
            '+1-242' => 'BS +1',
            '+973' => 'BH +973',
            '+880' => 'BD +880',
            '+1-246' => 'BB +1',
            '+375' => 'BY +375',
            '+32' => 'BE +32',
            '+501' => 'BZ +501',
            '+229' => 'BJ +229',
            '+1-441' => 'BM +1',
            '+975' => 'BT +975',
            '+591' => 'BO +591',
            '+387' => 'BA +387',
            '+267' => 'BW +267',
            '+55' => 'BR +55',
            '+246' => 'IO +246',
            '+673' => 'BN +673',
            '+359' => 'BG +359',
            '+226' => 'BF +226',
            '+257' => 'BI +257',
            '+855' => 'KH +855',
            '+237' => 'CM +237',
            '+1' => 'US/CA +1', // Primary for +1
            '+238' => 'CV +238',
            '+1-345' => 'KY +1',
            '+236' => 'CF +236',
            '+235' => 'TD +235',
            '+56' => 'CL +56',
            '+86' => 'CN +86',
            '+57' => 'CO +57',
            '+269' => 'KM +269',
            '+242' => 'CG +242',
            '+243' => 'CD +243',
            '+682' => 'CK +682',
            '+506' => 'CR +506',
            '+225' => 'CI +225',
            '+385' => 'HR +385',
            '+53' => 'CU +53',
            '+357' => 'CY +357',
            '+420' => 'CZ +420',
            '+45' => 'DK +45',
            '+253' => 'DJ +253',
            '+1-767' => 'DM +1',
            '+1-809' => 'DO +1',
            '+1-829' => 'DO +1',
            '+1-849' => 'DO +1',
            '+593' => 'EC +593',
            '+20' => 'EG +20',
            '+503' => 'SV +503',
            '+240' => 'GQ +240',
            '+291' => 'ER +291',
            '+372' => 'EE +372',
            '+251' => 'ET +251',
            '+500' => 'FK +500',
            '+298' => 'FO +298',
            '+679' => 'FJ +679',
            '+358' => 'FI +358',
            '+33' => 'FR +33',
            '+594' => 'GF +594',
            '+689' => 'PF +689',
            '+241' => 'GA +241',
            '+220' => 'GM +220',
            '+995' => 'GE +995',
            '+49' => 'DE +49',
            '+233' => 'GH +233',
            '+350' => 'GI +350',
            '+30' => 'GR +30',
            '+299' => 'GL +299',
            '+1-473' => 'GD +1',
            '+590' => 'GP +590',
            '+1-671' => 'GU +1',
            '+502' => 'GT +502',
            '+44-1481' => 'GG +44',
            '+224' => 'GN +224',
            '+245' => 'GW +245',
            '+592' => 'GY +592',
            '+509' => 'HT +509',
            '+379' => 'VA +379',
            '+504' => 'HN +504',
            '+852' => 'HK +852',
            '+36' => 'HU +36',
            '+354' => 'IS +354',
            '+91' => 'IN +91',
            '+62' => 'ID +62',
            '+98' => 'IR +98',
            '+964' => 'IQ +964',
            '+353' => 'IE +353',
            '+44-1624' => 'IM +44',
            '+972' => 'IL +972',
            '+39' => 'IT +39',
            '+1-876' => 'JM +1',
            '+81' => 'JP +81',
            '+44-1534' => 'JE +44',
            '+962' => 'JO +962',
            '+7' => 'KZ/RU +7', // Primary for +7
            '+254' => 'KE +254',
            '+686' => 'KI +686',
            '+850' => 'KP +850',
            '+82' => 'KR +82',
            '+965' => 'KW +965',
            '+996' => 'KG +996',
            '+856' => 'LA +856',
            '+371' => 'LV +371',
            '+961' => 'LB +961',
            '+266' => 'LS +266',
            '+231' => 'LR +231',
            '+218' => 'LY +218',
            '+423' => 'LI +423',
            '+370' => 'LT +370',
            '+352' => 'LU +352',
            '+853' => 'MO +853',
            '+389' => 'MK +389',
            '+261' => 'MG +261',
            '+265' => 'MW +265',
            '+60' => 'MY +60',
            '+960' => 'MV +960',
            '+223' => 'ML +223',
            '+356' => 'MT +356',
            '+692' => 'MH +692',
            '+596' => 'MQ +596',
            '+222' => 'MR +222',
            '+230' => 'MU +230',
            '+262' => 'YT/RE +262', // Primary for +262
            '+52' => 'MX +52',
            '+691' => 'FM +691',
            '+373' => 'MD +373',
            '+377' => 'MC +377',
            '+976' => 'MN +976',
            '+382' => 'ME +382',
            '+1-664' => 'MS +1',
            '+212' => 'MA/EH +212', // Primary for +212
            '+258' => 'MZ +258',
            '+95' => 'MM +95',
            '+264' => 'NA +264',
            '+674' => 'NR +674',
            '+977' => 'NP +977',
            '+31' => 'NL +31',
            '+687' => 'NC +687',
            '+64' => 'NZ/PN +64', // Primary for +64
            '+505' => 'NI +505',
            '+227' => 'NE +227',
            '+234' => 'NG +234',
            '+683' => 'NU +683',
            '+672' => 'NF/AQ +672', // Primary for +672
            '+1-670' => 'MP +1',
            '+47' => 'NO/SJ +47', // Primary for +47
            '+968' => 'OM +968',
            '+92' => 'PK +92',
            '+680' => 'PW +680',
            '+970' => 'PS +970',
            '+507' => 'PA +507',
            '+675' => 'PG +675',
            '+595' => 'PY +595',
            '+51' => 'PE +51',
            '+63' => 'PH +63',
            '+48' => 'PL +48',
            '+351' => 'PT +351',
            '+1-787' => 'PR +1',
            '+1-939' => 'PR +1',
            '+974' => 'QA +974',
            '+40' => 'RO +40',
            '+250' => 'RW +250',
            '+590' => 'BL/MF/GP +590', // Primary for +590
            '+290' => 'SH +290',
            '+1-869' => 'KN +1',
            '+1-758' => 'LC +1',
            '+508' => 'PM +508',
            '+1-784' => 'VC +1',
            '+685' => 'WS +685',
            '+378' => 'SM +378',
            '+239' => 'ST +239',
            '+966' => 'SA +966',
            '+221' => 'SN +221',
            '+381' => 'RS +381',
            '+248' => 'SC +248',
            '+232' => 'SL +232',
            '+65' => 'SG +65',
            '+1-721' => 'SX +1',
            '+421' => 'SK +421',
            '+386' => 'SI +386',
            '+677' => 'SB +677',
            '+252' => 'SO +252',
            '+27' => 'ZA +27',
            '+211' => 'SS +211',
            '+34' => 'ES +34',
            '+94' => 'LK +94',
            '+249' => 'SD +249',
            '+597' => 'SR +597',
            '+268' => 'SZ +268',
            '+46' => 'SE +46',
            '+41' => 'CH +41',
            '+963' => 'SY +963',
            '+886' => 'TW +886',
            '+992' => 'TJ +992',
            '+255' => 'TZ +255',
            '+66' => 'TH +66',
            '+670' => 'TL +670',
            '+228' => 'TG +228',
            '+690' => 'TK +690',
            '+676' => 'TO +676',
            '+1-868' => 'TT +1',
            '+216' => 'TN +216',
            '+90' => 'TR +90',
            '+993' => 'TM +993',
            '+1-649' => 'TC +1',
            '+688' => 'TV +688',
            '+256' => 'UG +256',
            '+380' => 'UA +380',
            '+971' => 'AE +971',
            '+44' => 'GB +44',
            '+598' => 'UY +598',
            '+998' => 'UZ +998',
            '+678' => 'VU +678',
            '+58' => 'VE +58',
            '+84' => 'VN +84',
            '+1-284' => 'VG +1',
            '+1-340' => 'VI +1',
            '+681' => 'WF +681',
            '+967' => 'YE +967',
            '+260' => 'ZM +260',
            '+263' => 'ZW +263',
        ];
        return apply_filters('wca_country_dial_codes', $codes);
    }

    // --- Settings helpers -------------------------------------------------

    public static function textmagic_username(): string
    {
        return self::site_option('wca_textmagic_username', '');
    }

    public static function textmagic_api_key(): string
    {
        return self::site_option('wca_textmagic_api_key', '');
    }

    public static function recaptcha_site_key(): string
    {
        return self::site_option('wca_recaptcha_site_key', '');
    }

    public static function recaptcha_secret_key(): string
    {
        return self::site_option('wca_recaptcha_secret_key', '');
    }

    public static function otp_ttl(): int
    {
        return (int) self::site_option('wca_otp_ttl', WCA_OTP_TTL);
    }

    /**
     * Max SMS-dispatching requests per IP per sms_rate_window().
     *
     * Counts only requests that actually send a message - validation
     * failures are free. 10 leaves room for shared IPs (office wifi, and
     * especially mobile carrier-grade NAT, where many customers appear as
     * one address) without meaningfully helping an attacker: the July
     * incident ran 1,021 sends from a single IP.
     */
    public static function sms_rate_limit(): int
    {
        return (int) self::site_option('wca_sms_rate_limit', 10);
    }

    public static function sms_rate_window(): int
    {
        return (int) self::site_option('wca_sms_rate_window', 900);
    }

    /** Max codes to a single destination number per sms_phone_window(). */
    public static function sms_phone_limit(): int
    {
        return (int) self::site_option('wca_sms_phone_limit', 3);
    }

    public static function sms_phone_window(): int
    {
        return (int) self::site_option('wca_sms_phone_window', 3600);
    }

    /** Network-wide circuit breaker: max SMS per sms_global_window(). 0 = off. */
    public static function sms_global_limit(): int
    {
        return (int) self::site_option('wca_sms_global_limit', 100);
    }

    public static function sms_global_window(): int
    {
        return (int) self::site_option('wca_sms_global_window', 3600);
    }

    /** Master kill switch for all SMS dispatch. */
    public static function sms_enabled(): bool
    {
        return (bool) self::site_option('wca_sms_enabled', 1);
    }

    // --- Email abuse controls ---------------------------------------------
    // Email costs nothing per message, so the risk it carries is different:
    // a flood does not produce an invoice, it produces spam complaints and a
    // throttled or blacklisted sending domain - which silently breaks order
    // confirmations on a WooCommerce store. Hence the same shape of limits.

    /** Max emails to one recipient address per email_recipient_window(). */
    public static function email_recipient_limit(): int
    {
        return (int) self::site_option('wca_email_recipient_limit', 5);
    }

    public static function email_recipient_window(): int
    {
        return (int) self::site_option('wca_email_recipient_window', 3600);
    }

    /** Network-wide email circuit breaker. 0 = off. */
    public static function email_global_limit(): int
    {
        return (int) self::site_option('wca_email_global_limit', 200);
    }

    public static function email_global_window(): int
    {
        return (int) self::site_option('wca_email_global_window', 3600);
    }

    /**
     * Country dial codes we are willing to send SMS to, without the '+'.
     * Matched as a prefix, so '35' covers 353/351/358 etc.
     *
     * Empty array means "no restriction" - strongly discouraged, since an
     * unrestricted destination list is what makes SMS pumping profitable.
     *
     * The default is derived from 3 days of real dispatch logs: 71% of
     * legitimate traffic is UK, but 29% is not (IE/PT, IT, AE, CH, FR, BE).
     * A UK-only default would have blocked roughly one real customer in
     * three. It still excludes both ranges used in the July incident
     * (+99x and +38x). Operators should narrow this to the countries they
     * actually ship to.
     */
    public static function sms_allowed_countries(): array
    {
        $raw = (string) self::site_option('wca_sms_allowed_countries', '44,35,39,97,41,33,32');

        $codes = array_filter(array_map(
            static fn($code) => preg_replace('/\D/', '', $code),
            explode(',', $raw)
        ));

        return array_values(array_unique($codes));
    }

    /**
     * $_SERVER key of the proxy header that may be trusted for the client IP,
     * or '' when the site is not behind a trusted proxy. See
     * WCA_Rate_Limiter::get_client_ip().
     */
    public static function trusted_proxy_header(): string
    {
        return (string) self::site_option('wca_trusted_proxy_header', '');
    }

    public static function recaptcha_threshold(): float
    {
        return (float) self::site_option('wca_recaptcha_threshold', 0.5);
    }

    /**
     * When true, a reCAPTCHA outage or missing key lets requests through.
     * Defaults to false: an unverifiable request must not be able to spend
     * SMS credit.
     */
    public static function recaptcha_fail_open(): bool
    {
        return (bool) self::site_option('wca_recaptcha_fail_open', 0);
    }

    public static function migration_run(): bool
    {
        return (bool) self::site_option('wca_migration_run', false);
    }

    // --- Log path ---------------------------------------------------------

    public static function log_path(): string
    {
        return WCA_LOG_PATH;
    }

    // --- Transient key builders -------------------------------------------

    public static function transient_registration(string $token): string
    {
        return 'wca_pending_' . $token;
    }

    public static function transient_login_otp(int $user_id, int $timestamp): string
    {
        return 'wca_login_otp_' . $user_id . '_' . $timestamp;
    }

    public static function transient_profile_update(int $user_id): string
    {
        return 'wca_profile_upd_' . $user_id;
    }

    public static function transient_rate_limit(string $ip): string
    {
        return 'wca_rl_' . md5($ip . AUTH_SALT);
    }

    public static function transient_phone_limit(string $phone): string
    {
        return 'wca_rlp_' . md5($phone . AUTH_SALT);
    }

    public static function transient_email_limit(string $email): string
    {
        return 'wca_rle_' . md5(strtolower($email) . AUTH_SALT);
    }

    // --- Encryption key (derived from WP AUTH_KEY constant) ---------------

    public static function encryption_key(): string
    {
        // 32 bytes required for AES-256. Hash AUTH_KEY to normalise length.
        return hash('sha256', AUTH_KEY, true);
    }

    // --- Internal cache wrapper -------------------------------------------

    private static function site_option(string $key, mixed $default): mixed
    {
        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = get_site_option($key, $default);
        }
        return self::$cache[$key];
    }

    /** Flush cache - useful after settings are saved. */
    public static function flush_cache(): void
    {
        self::$cache = [];
    }
}
