<?php
/**
 * Template: modal-otp-verify.php
 * Generic OTP verification modal used for reverification prompts.
 * Opens when ?wca_modal=reverify is in the URL (triggered by checkout guard).
 */
defined('ABSPATH') || exit;

// Only render this modal if the user is logged in and needs reverification.
if (!is_user_logged_in()) {
    return;
}

$user_id = get_current_user_id();
$needs_reverify = get_user_meta($user_id, 'wca_needs_reverification', true);

if (!$needs_reverify) {
    return;
}
?>
<div id="wca-modal-reverify" class="wca-modal-backdrop" hidden>
    <div class="wca-modal" role="dialog" aria-modal="true" aria-labelledby="wca-reverify-title" x-data="{
            step: 'intro',
            channel: 'sms',
            otp: '',
            sessionToken: '',
            loading: false,
            error: '',
            countdown: 0,
            countdownTimer: null,
            resendCooldown: 0,
            resendTimer: null,

            async requestCode() {
                this.error   = '';
                this.loading = true;
                const token  = await wcaGetRecaptchaToken('reverify');
                const res    = await wcaFetch('/login/send-otp', {
                    identifier:      '<?php echo esc_js(wp_get_current_user()->user_email); ?>',
                    recaptcha_token: token,
                    channel:         this.channel,
                });
                this.loading = false;
                if (!res.ok) { this.error = res.data?.message || 'Failed to send code.'; return; }
                this.sessionToken = res.data.session_token;
                this.step         = 'otp';
                this.startCountdown(res.data.expires_in || 600);
            },

            async verifyCode(code) {
                if (code.length < 6) return;
                this.error   = '';
                this.loading = true;
                const res    = await wcaFetch('/login/authenticate', {
                    identifier:    '<?php echo esc_js(wp_get_current_user()->user_email); ?>',
                    otp_code:      code,
                    session_token: this.sessionToken,
                });
                this.loading = false;
                if (!res.ok) { this.error = res.data?.message || 'Invalid code.'; return; }
                this.step = 'done';
                setTimeout(() => window.location.reload(), 1200);
            },

            startCountdown(s) {
                clearInterval(this.countdownTimer);
                this.countdown = s;
                this.countdownTimer = setInterval(() => {
                    this.countdown--;
                    if (this.countdown <= 0) clearInterval(this.countdownTimer);
                }, 1000);
            },

            get countdownFormatted() {
                const m = Math.floor(this.countdown / 60);
                const s = String(this.countdown % 60).padStart(2, '0');
                return m + ':' + s;
            }
        }">
        <!-- -- Intro ---------------------------------------------------- -->
        <div x-show="step === 'intro'">
            <h2 class="wca-modal__title" id="wca-reverify-title">
                <?php esc_html_e('Verify Your Account', 'wca-auth-engine'); ?>
            </h2>
            <p class="wca-modal__subtitle">
                <?php esc_html_e('Your account requires re-verification before you can proceed. Choose a method to receive your code.', 'wca-auth-engine'); ?>
            </p>

            <div class="wca-alert wca-alert-error" x-show="error" x-text="error" role="alert"></div>

            <div class="wca-channel-toggle" style="margin-bottom:20px;">
                <button :class="{ active: channel === 'sms' }" @click="channel = 'sms'">
                     <?php esc_html_e('SMS Code', 'wca-auth-engine'); ?>
                </button>
                <button :class="{ active: channel === 'email' }" @click="channel = 'email'">
                     <?php esc_html_e('Email Code', 'wca-auth-engine'); ?>
                </button>
            </div>

            <button class="wca-btn wca-btn-primary" @click="requestCode" :disabled="loading">
                <span class="wca-spinner" x-show="loading"></span>
                <span
                    x-text="loading ? '<?php esc_html_e('Sending', 'wca-auth-engine'); ?>' : '<?php esc_html_e('Send Verification Code', 'wca-auth-engine'); ?>'"></span>
            </button>
        </div>

        <!-- -- OTP ------------------------------------------------------ -->
        <div x-show="step === 'otp'">
            <h2 class="wca-modal__title"><?php esc_html_e('Enter Verification Code', 'wca-auth-engine'); ?></h2>
            <p class="wca-modal__subtitle"
                x-text="channel === 'sms' ? '<?php esc_js(__('Enter the 6-digit code sent to your phone.', 'wca-auth-engine')); ?>' : '<?php esc_js(__('Enter the 6-digit code sent to your email.', 'wca-auth-engine')); ?>'">
            </p>

            <div class="wca-alert wca-alert-error" x-show="error" x-text="error" role="alert"></div>

            <div class="wca-otp-grid" data-wca-otp-group @wca:otp-complete="verifyCode($event.detail.code)">
                <input data-wca-otp-index="0" type="text" inputmode="numeric" placeholder="" aria-label="Digit 1">
                <input data-wca-otp-index="1" type="text" inputmode="numeric" placeholder="" aria-label="Digit 2">
                <input data-wca-otp-index="2" type="text" inputmode="numeric" placeholder="" aria-label="Digit 3">
                <input data-wca-otp-index="3" type="text" inputmode="numeric" placeholder="" aria-label="Digit 4">
                <input data-wca-otp-index="4" type="text" inputmode="numeric" placeholder="" aria-label="Digit 5">
                <input data-wca-otp-index="5" type="text" inputmode="numeric" placeholder="" aria-label="Digit 6">
            </div>

            <div class="wca-otp-status">
                <span><?php esc_html_e('Expires in', 'wca-auth-engine'); ?> <span
                        x-text="countdownFormatted"></span></span>
                <button class="wca-btn-link" @click="step = 'intro'">
                    &larr; <?php esc_html_e('Change method', 'wca-auth-engine'); ?>
                </button>
            </div>
        </div>

        <!-- -- Done ---------------------------------------------------- -->
        <div x-show="step === 'done'" style="text-align:center;padding:20px 0;">
            <div class="wca-success-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <h2 class="wca-modal__title"><?php esc_html_e('Account Verified!', 'wca-auth-engine'); ?></h2>
            <p class="wca-modal__subtitle"><?php esc_html_e('Reloading your page', 'wca-auth-engine'); ?></p>
        </div>

    </div>
</div>