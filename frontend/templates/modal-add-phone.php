<?php
/**
 * Template: modal-add-phone.php
 * Shown to logged-in legacy users who have no billing_phone on record.
 * Opens when ?wca_modal=add-phone is in the URL (triggered by checkout guard).
 */
defined('ABSPATH') || exit;

// Only render if the user is logged in and genuinely has no phone.
if (!is_user_logged_in()) {
    return;
}

$user_id = get_current_user_id();
$phone = get_user_meta($user_id, 'billing_phone', true);

if (!empty(trim((string) $phone))) {
    return; // Phone already exists -- nothing to do.
}
?>
<div id="wca-modal-add-phone" class="wca-modal-backdrop" hidden>
    <div class="wca-modal" role="dialog" aria-modal="true" aria-labelledby="wca-add-phone-title" x-data="{
            step: 'phone',
            phone: '',
            dialCode: '+44',
            phoneNumber: '',
            sessionToken: '',
            loading: false,
            error: '',
            countdown: 0,
            countdownTimer: null,
            resendCooldown: 0,
            resendTimer: null,

            init() {
                this.$watch('dialCode', () => { this.updatePhone(); });
                this.$watch('phoneNumber', () => { this.updatePhone(); });
            },

            updatePhone() {
                if (this.phoneNumber) {
                    this.phone = this.dialCode + this.phoneNumber;
                } else {
                    this.phone = '';
                }
            },

            async sendOtp() {
                this.error = '';
                if (!this.phone) {
                    this.error = '<?php esc_html_e('Please enter your mobile phone number.', 'wca-auth-engine'); ?>';
                    return;
                }
                this.loading = true;
                const token = await wcaGetRecaptchaToken('add_phone');
                const res   = await wcaFetch('/profile/add-phone/send-otp', {
                    phone:           this.phone,
                    recaptcha_token: token,
                });
                this.loading = false;
                if (!res.ok) { this.error = res.data?.message || '<?php esc_html_e('Failed to send code. Please check your number.', 'wca-auth-engine'); ?>'; return; }
                this.sessionToken = res.data.session_token;
                this.step = 'otp';
                this.startCountdown(res.data.expires_in || 600);
                this.startResendCooldown(60);
            },

            async verifyOtp(code) {
                if (code.length < 6) return;
                this.error   = '';
                this.loading = true;
                const res    = await wcaFetch('/profile/add-phone/verify-otp', {
                    phone:         this.phone,
                    otp_code:      code,
                    session_token: this.sessionToken,
                });
                this.loading = false;
                if (!res.ok) { this.error = res.data?.message || '<?php esc_html_e('Invalid code. Please try again.', 'wca-auth-engine'); ?>'; return; }
                this.step = 'done';
                setTimeout(() => window.location.href = '<?php echo esc_js(wc_get_checkout_url()); ?>', 1500);
            },

            async resendOtp() {
                if (this.resendCooldown > 0) return;
                this.sendOtp();
            },

            startCountdown(s) {
                clearInterval(this.countdownTimer);
                this.countdown = s;
                this.countdownTimer = setInterval(() => {
                    this.countdown--;
                    if (this.countdown <= 0) clearInterval(this.countdownTimer);
                }, 1000);
            },

            startResendCooldown(s) {
                clearInterval(this.resendTimer);
                this.resendCooldown = s;
                this.resendTimer = setInterval(() => {
                    this.resendCooldown--;
                    if (this.resendCooldown <= 0) clearInterval(this.resendTimer);
                }, 1000);
            },

            get countdownFormatted() {
                const m = Math.floor(this.countdown / 60);
                const s = String(this.countdown % 60).padStart(2, '0');
                return m + ':' + s;
            }
        }">

        <!-- Step 1: Enter Phone -->
        <div x-show="step === 'phone'">
            <h2 class="wca-modal__title" id="wca-add-phone-title">
                <?php esc_html_e('Add Your Mobile Number', 'wca-auth-engine'); ?>
            </h2>
            <p class="wca-modal__subtitle">
                <?php esc_html_e('Your account needs a verified mobile number before you can place an order. It only takes a moment.', 'wca-auth-engine'); ?>
            </p>

            <div class="wca-alert wca-alert-error" x-show="error" x-text="error" role="alert"></div>

            <div class="wca-field">
                <label for="wca-add-phone-input"><?php esc_html_e('Mobile Phone', 'wca-auth-engine'); ?></label>
                <div class="wca-phone-row">
                    <select id="wca-add-phone-dialcode" x-model="dialCode" class="wca-dialcode-select"
                        aria-label="<?php esc_attr_e('Country code', 'wca-auth-engine'); ?>">
                        <?php foreach (WCA_Constants::get_country_dial_codes() as $code => $label): ?>
                            <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input id="wca-add-phone-input" type="tel" x-model="phoneNumber" placeholder="7911123456"
                        autocomplete="tel" inputmode="numeric" @input="phoneNumber = phoneNumber.replace(/\D/g, '')"
                        @keypress="if(!/\d/.test($event.key)) $event.preventDefault()" @keydown.enter="sendOtp">
                </div>
                <span class="wca-field-hint" style="display:block; margin-top:5px; font-size:0.78rem;">
                    <?php esc_html_e('Select your country code then enter your number digits only.', 'wca-auth-engine'); ?>
                </span>
            </div>

            <button class="wca-btn wca-btn-primary" @click="sendOtp" :disabled="loading">
                <span class="wca-spinner" x-show="loading"></span>
                <span
                    x-text="loading ? '<?php esc_html_e('Sending Code...', 'wca-auth-engine'); ?>' : '<?php esc_html_e('Send Verification Code', 'wca-auth-engine'); ?>'"></span>
            </button>

            <p style="text-align:center;margin-top:15px;font-size:0.85rem;">
                <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"
                    style="color:var(--wca-text-muted);text-decoration:none;">
                    &larr; <?php esc_html_e('Return to Shop', 'wca-auth-engine'); ?>
                </a>
            </p>
        </div>

        <!-- Step 2: OTP Verify -->
        <div x-show="step === 'otp'">
            <h2 class="wca-modal__title"><?php esc_html_e('Enter Your Code', 'wca-auth-engine'); ?></h2>
            <p class="wca-modal__subtitle">
                <?php esc_html_e('We sent a 6-digit code to', 'wca-auth-engine'); ?>
                <strong x-text="phone"></strong>
            </p>

            <div class="wca-alert wca-alert-error" x-show="error" x-text="error" role="alert"></div>

            <div class="wca-otp-grid" data-wca-otp-group @wca:otp-complete="verifyOtp($event.detail.code)">
                <input data-wca-otp-index="0" type="text" inputmode="numeric" placeholder="*" aria-label="Digit 1">
                <input data-wca-otp-index="1" type="text" inputmode="numeric" placeholder="*" aria-label="Digit 2">
                <input data-wca-otp-index="2" type="text" inputmode="numeric" placeholder="*" aria-label="Digit 3">
                <input data-wca-otp-index="3" type="text" inputmode="numeric" placeholder="*" aria-label="Digit 4">
                <input data-wca-otp-index="4" type="text" inputmode="numeric" placeholder="*" aria-label="Digit 5">
                <input data-wca-otp-index="5" type="text" inputmode="numeric" placeholder="*" aria-label="Digit 6">
            </div>

            <div class="wca-otp-status" :class="{ 'wca-otp-status--expiring': countdown <= 60 }">
                <span><?php esc_html_e('Expires in', 'wca-auth-engine'); ?> <span
                        x-text="countdownFormatted"></span></span>
                <button class="wca-btn-link" @click="resendOtp" :disabled="resendCooldown > 0">
                    <span x-show="resendCooldown > 0"><?php esc_html_e('Resend in', 'wca-auth-engine'); ?> <span
                            x-text="resendCooldown"></span>s</span>
                    <span x-show="resendCooldown === 0"><?php esc_html_e('Resend code', 'wca-auth-engine'); ?></span>
                </button>
            </div>

            <p x-show="resendCooldown > 0"
                style="font-size: 0.78rem; color: var(--wca-text-muted); margin-top: 16px; text-align: center; line-height: 1.4;">
                <em><?php esc_html_e('Please wait patiently for your code to arrive. Ensure your mobile is on and has signal. If you entered the wrong number, you can go back to update it.', 'wca-auth-engine'); ?></em>
            </p>

            <p style="text-align:center;margin-top:16px;">
                <button class="wca-btn-link" @click="step = 'phone'">&larr;
                    <?php esc_html_e('Back', 'wca-auth-engine'); ?></button>
            </p>
        </div>

        <!-- Step 3: Success -->
        <div x-show="step === 'done'" style="text-align:center;padding:20px 0;">
            <div class="wca-success-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <h2 class="wca-modal__title"><?php esc_html_e('Phone Verified!', 'wca-auth-engine'); ?></h2>
            <p class="wca-modal__subtitle"><?php esc_html_e('Redirecting you to checkout...', 'wca-auth-engine'); ?></p>
        </div>

    </div>
</div>