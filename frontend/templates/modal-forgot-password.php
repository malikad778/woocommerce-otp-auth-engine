<?php
/**
 * Template: modal-forgot-password.php
 * Forgot Password modal - Alpine.js wcaForgotPassword() component.
 */
defined('ABSPATH') || exit;
?>
<div id="wca-modal-forgot-password" class="wca-modal-backdrop" hidden>
    <div class="wca-modal" role="dialog" aria-modal="true" aria-labelledby="wca-forgot-title"
        x-data="wcaForgotPassword()">
        <button class="wca-modal__close" aria-label="<?php esc_attr_e('Close', 'wca-auth-engine'); ?>"
            @click="$el.closest('.wca-modal-backdrop').setAttribute('hidden','')">
            &times;
        </button>

        <!-- -- Step 1: Identifier ------------------------------------------ -->
        <div x-show="step === 'identifier'">
            <h2 class="wca-modal__title" id="wca-forgot-title">
                <?php esc_html_e('Reset Password', 'wca-auth-engine'); ?>
            </h2>
            <p class="wca-modal__subtitle">
                <?php esc_html_e('Choose how you\'d like to receive your verification code.', 'wca-auth-engine'); ?>
            </p>

            <div class="wca-alert wca-alert-error" x-show="error" x-text="error" role="alert"></div>
            <div class="wca-alert wca-alert-success" x-show="success" x-text="success" role="alert"></div>

            <!-- Mode tabs -->
            <div class="wca-login-tabs" role="tablist">
                <button type="button" role="tab" :class="{ active: fpMode === 'email' }"
                    @click="fpMode = 'email'; error = ''; success = ''" :aria-selected="fpMode === 'email'"
                    id="wca-fp-tab-email" aria-controls="wca-fp-panel-email">
                    <?php esc_html_e('Email / Username', 'wca-auth-engine'); ?>
                </button>
                <button type="button" role="tab" :class="{ active: fpMode === 'sms' }"
                    @click="fpMode = 'sms'; error = ''; success = ''" :aria-selected="fpMode === 'sms'"
                    id="wca-fp-tab-sms" aria-controls="wca-fp-panel-sms">
                     <?php esc_html_e('Mobile Number', 'wca-auth-engine'); ?>
                </button>
            </div>

            <!-- Panel A: Email / Username -->
            <div id="wca-fp-panel-email" role="tabpanel" aria-labelledby="wca-fp-tab-email" x-show="fpMode === 'email'"
                class="wca-tab-panel">
                <div class="wca-field">
                    <label for="wca-forgot-id"><?php esc_html_e('Email or Username', 'wca-auth-engine'); ?></label>
                    <input id="wca-forgot-id" type="text" x-model="identifier"
                        placeholder="<?php esc_attr_e('you@example.com or johndoe', 'wca-auth-engine'); ?>"
                        autocomplete="username email" @keydown.enter="initiateReset">
                </div>
            </div>

            <!-- Panel B: Mobile number -->
            <div id="wca-fp-panel-sms" role="tabpanel" aria-labelledby="wca-fp-tab-sms" x-show="fpMode === 'sms'"
                class="wca-tab-panel">
                <div class="wca-field">
                    <label for="wca-forgot-phone"><?php esc_html_e('Mobile Number', 'wca-auth-engine'); ?></label>
                    <div class="wca-phone-row">
                        <select id="wca-forgot-dialcode" x-model="fpDialCode" class="wca-dialcode-select"
                            aria-label="<?php esc_attr_e('Country code', 'wca-auth-engine'); ?>">
                            <?php foreach (WCA_Constants::get_country_dial_codes() as $code => $label): ?>
                                <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input id="wca-forgot-phone" type="tel" x-model="fpPhoneNumber" placeholder="7911123456"
                            inputmode="numeric" autocomplete="tel-national"
                            @input="fpPhoneNumber = fpPhoneNumber.replace(/\D/g, '')"
                            @keypress="if(!/\d/.test($event.key)) $event.preventDefault()"
                            @keydown.enter="initiateReset">
                    </div>
                    <p style="font-size:0.78rem;color:var(--wca-text-muted);margin-top:5px;line-height:1.3;">
                        <?php esc_html_e('Select your country code then enter your number digits only.', 'wca-auth-engine'); ?>
                    </p>
                </div>
            </div>

            <button class="wca-btn wca-btn-primary" @click="initiateReset" :disabled="loading">
                <span class="wca-spinner" x-show="loading"></span>
                <span
                    x-text="loading ? '<?php esc_html_e('Sending Code', 'wca-auth-engine'); ?>' : '<?php esc_html_e('Send Verification Code', 'wca-auth-engine'); ?>'"></span>
            </button>

            <p style="text-align:center;margin-top:20px;font-size:0.85rem;color:var(--wca-text-muted);">
                <button class="wca-btn-link" data-wca-modal="login">&larr;
                    <?php esc_html_e('Back to Sign In', 'wca-auth-engine'); ?></button>
            </p>
        </div>

        <!-- -- Step 2: OTP Verification ------------------------------------ -->
        <div x-show="step === 'otp'">

            <h2 class="wca-modal__title"><?php esc_html_e('Verify Code', 'wca-auth-engine'); ?></h2>
            <p class="wca-modal__subtitle">
                <?php esc_html_e('Enter the 6-digit code sent to', 'wca-auth-engine'); ?>
                <strong x-text="identifier"></strong>
            </p>

            <div class="wca-alert wca-alert-error" x-show="error" x-text="error" role="alert"></div>
            <div class="wca-alert wca-alert-success" x-show="success" x-text="success" role="alert"></div>

            <div class="wca-otp-grid" data-wca-otp-group @wca:otp-complete="verifyOtp($event.detail.code)">
                <input data-wca-otp-index="0" type="text" inputmode="numeric" placeholder="" aria-label="Digit 1">
                <input data-wca-otp-index="1" type="text" inputmode="numeric" placeholder="" aria-label="Digit 2">
                <input data-wca-otp-index="2" type="text" inputmode="numeric" placeholder="" aria-label="Digit 3">
                <input data-wca-otp-index="3" type="text" inputmode="numeric" placeholder="" aria-label="Digit 4">
                <input data-wca-otp-index="4" type="text" inputmode="numeric" placeholder="" aria-label="Digit 5">
                <input data-wca-otp-index="5" type="text" inputmode="numeric" placeholder="" aria-label="Digit 6">
            </div>

            <div class="wca-otp-status" :class="{ 'wca-otp-status--expiring': countdown <= 60 }">
                <span><?php esc_html_e('Expires in', 'wca-auth-engine'); ?> <span
                        x-text="Math.floor(countdown/60) + ':' + (countdown%60).toString().padStart(2, '0')"></span></span>
                <button class="wca-btn-link" @click="resendOtp" :disabled="resendCooldown > 0">
                    <span x-show="resendCooldown > 0"><?php esc_html_e('Resend in', 'wca-auth-engine'); ?> <span
                            x-text="resendCooldown"></span>s</span>
                    <span x-show="resendCooldown === 0"><?php esc_html_e('Resend code', 'wca-auth-engine'); ?></span>
                </button>
            </div>

            <p style="text-align:center;margin-top:16px;">
                <button class="wca-btn-link" @click="step = 'identifier'">&larr;
                    <?php esc_html_e('Back', 'wca-auth-engine'); ?></button>
            </p>
        </div>

        <!-- -- Step 3: New Password ---------------------------------------- -->
        <div x-show="step === 'reset'">
            <h2 class="wca-modal__title"><?php esc_html_e('Create New Password', 'wca-auth-engine'); ?></h2>
            <p class="wca-modal__subtitle">
                <?php esc_html_e('Please choose a strong password.', 'wca-auth-engine'); ?>
            </p>

            <div class="wca-alert wca-alert-error" x-show="error" x-text="error" role="alert"></div>

            <div class="wca-field wca-field--password">
                <label for="wca-reset-pass"><?php esc_html_e('New Password', 'wca-auth-engine'); ?></label>
                <input id="wca-reset-pass" :type="showPass ? 'text' : 'password'" x-model="password"
                    placeholder="<?php esc_attr_e('At least 8 characters', 'wca-auth-engine'); ?>"
                    autocomplete="new-password" @keydown.enter="completeReset">
                <button type="button" class="wca-btn-reveal" @click="showPass = !showPass"
                    :aria-label="showPass ? '<?php esc_attr_e('Hide password', 'wca-auth-engine'); ?>' : '<?php esc_attr_e('Show password', 'wca-auth-engine'); ?>'">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        style="width:18px;height:18px">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                </button>
                <p class="wca-field-hint">
                    <?php esc_html_e('Must include at least 1 uppercase letter and 1 number.', 'wca-auth-engine'); ?>
                </p>
            </div>

            <button class="wca-btn wca-btn-primary" @click="completeReset" :disabled="loading">
                <span class="wca-spinner" x-show="loading"></span>
                <span
                    x-text="loading ? '<?php esc_html_e('Resetting Password', 'wca-auth-engine'); ?>' : '<?php esc_html_e('Reset Password', 'wca-auth-engine'); ?>'"></span>
            </button>
        </div>

        <!-- -- Step 4: Done ------------------------------------------------ -->
        <div x-show="step === 'done'" style="text-align:center; padding: 20px 0;">
            <div style="font-size: 48px; color: var(--wca-success); margin-bottom: 20px;">
                
            </div>
            <h2 class="wca-modal__title"><?php esc_html_e('Password Reset Complete!', 'wca-auth-engine'); ?></h2>
            <p class="wca-modal__subtitle">
                <?php esc_html_e('Your password has been successfully updated.', 'wca-auth-engine'); ?>
            </p>
            <button class="wca-btn wca-btn-primary" @click="goToLogin" style="margin-top: 20px;">
                <?php esc_html_e('Sign In Now', 'wca-auth-engine'); ?>
            </button>
        </div>

    </div>
</div>