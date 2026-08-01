<?php
/**
 * Template: modal-reverify-email.php
 * Email-only verification modal for legacy accounts whose billing_email_verified = 0.
 * Opens when ?wca_modal=reverify-email is in the URL (set by WCA_Checkout_Guard).
 *
 * Uses /profile/update-initiate + /profile/verify-update endpoints:
 * - Sends an OTP to the user's current (unchanged) email address.
 * - On success, sets billing_email_verified = 1 and clears wca_needs_reverification.
 *
 * Rendered via wca_render_modal_templates() in wp_footer.
 */
defined('ABSPATH') || exit;

// Only render for logged-in users with unverified email.
if (!is_user_logged_in()) {
    return;
}

$user_id = get_current_user_id();
$email_verified = get_user_meta($user_id, 'billing_email_verified', true);

if ($email_verified) {
    return; // Email is already verified - no need to render this modal.
}

$current_email = wp_get_current_user()->user_email;
?>
<div id="wca-modal-reverify-email" class="wca-modal-backdrop" hidden>
    <div class="wca-modal" role="dialog" aria-modal="true" aria-labelledby="wca-reverify-email-title" x-data="{
            step: 'intro',
            loading: false,
            error: '',
            countdown: 0,
            countdownTimer: null,

            async sendCode() {
                this.error   = '';
                this.loading = true;
                const token  = await wcaGetRecaptchaToken('reverify_email');
                const res    = await wcaFetch('/profile/update-initiate', {
                    email:           '<?php echo esc_js($current_email); ?>',
                    recaptcha_token: token,
                }, { nonce: true });
                this.loading = false;
                if (!res.ok) { this.error = res.data?.message || '<?php echo esc_js(__('Failed to send code. Please try again.', 'wca-auth-engine')); ?>'; return; }
                this.step = 'otp';
                this.startCountdown(res.data.expires_in || 600);
            },

            async verifyCode(code) {
                if (code.length < 6) return;
                this.error   = '';
                this.loading = true;
                const res    = await wcaFetch('/profile/verify-update', {
                    channel:  'email',
                    otp_code: code,
                }, { nonce: true });
                this.loading = false;
                if (!res.ok) { this.error = res.data?.message || '<?php echo esc_js(__('Invalid code. Please try again.', 'wca-auth-engine')); ?>'; return; }
                this.step = 'done';
                // On success, redirect back to checkout (strips the reverify-email param).
                const returnTo = new URLSearchParams(window.location.search).get('return_to');
                setTimeout(() => {
                    window.location.href = returnTo || '<?php echo esc_js(wc_get_checkout_url()); ?>';
                }, 1500);
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

        <!-- -- Intro ------------------------------------------------- -->
        <div x-show="step === 'intro'">
            <h2 class="wca-modal__title" id="wca-reverify-email-title">
                <?php esc_html_e('Verify Your Email Address', 'wca-auth-engine'); ?>
            </h2>
            <p class="wca-modal__subtitle">
                <?php
                printf(
                    /* translators: %s = masked email address */
                    esc_html__('Before you can checkout, we need to verify your email address. We\'ll send a 6-digit code to %s.', 'wca-auth-engine'),
                    '<strong>' . esc_html(WCA_Logger::mask_email($current_email)) . '</strong>'
                );
                ?>
            </p>

            <div class="wca-alert wca-alert-error" x-show="error" x-text="error" role="alert"></div>

            <button class="wca-btn wca-btn-primary" @click="sendCode" :disabled="loading">
                <span class="wca-spinner" x-show="loading"></span>
                <span
                    x-text="loading ? '<?php echo esc_js(__('Sending', 'wca-auth-engine')); ?>' : '<?php echo esc_js(__('Send Verification Code', 'wca-auth-engine')); ?>'"></span>
            </button>
        </div>

        <!-- -- OTP -------------------------------------------------- -->
        <div x-show="step === 'otp'">
            <h2 class="wca-modal__title"><?php esc_html_e('Enter Verification Code', 'wca-auth-engine'); ?></h2>
            <p class="wca-modal__subtitle">
                <?php esc_html_e('Enter the 6-digit code sent to your email address.', 'wca-auth-engine'); ?>
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
                    &larr; <?php esc_html_e('Resend code', 'wca-auth-engine'); ?>
                </button>
            </div>
        </div>

        <!-- -- Done ------------------------------------------------ -->
        <div x-show="step === 'done'" style="text-align:center;padding:20px 0;">
            <div class="wca-success-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <h2 class="wca-modal__title"><?php esc_html_e('Email Verified!', 'wca-auth-engine'); ?></h2>
            <p class="wca-modal__subtitle"><?php esc_html_e('Redirecting you to checkout', 'wca-auth-engine'); ?></p>
        </div>

    </div>
</div>

<script>
    // Auto-open this modal if ?wca_modal=reverify-email is in the URL.
    document.addEventListener('DOMContentLoaded', function () {
        var params = new URLSearchParams(window.location.search);
        if (params.get('wca_modal') === 'reverify-email') {
            var modal = document.getElementById('wca-modal-reverify-email');
            if (modal) {
                modal.removeAttribute('hidden');
            }
        }
    });
</script>