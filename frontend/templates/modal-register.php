<?php
/**
 * Template: modal-register.php
 * Registration modal - Alpine.js wcaRegister() component.
 * Rendered via wca_render_modal_templates() in wp_footer.
 */
defined('ABSPATH') || exit;
?>
<div id="wca-modal-register" class="wca-modal-backdrop" hidden>
    <div class="wca-modal" role="dialog" aria-modal="true" aria-labelledby="wca-register-title" x-data="wcaRegister()"
        x-init="$nextTick(() => { window._wcaRegisterComponent = $data; })">
        <!-- Close -->
        <button class="wca-modal__close" aria-label="<?php esc_attr_e('Close', 'wca-auth-engine'); ?>"
            @click="$el.closest('.wca-modal-backdrop').setAttribute('hidden','')">
            &times;
        </button>

        <!-- -- Step 1: Registration Form -------------------------------- -->
        <div x-show="step === 'form'">
            <!-- Progress -->
            <div class="wca-steps">
                <div class="wca-step active"></div>
                <div class="wca-step"></div>
                <div class="wca-step"></div>
            </div>

            <h2 class="wca-modal__title" id="wca-register-title">
                <?php esc_html_e('Create Your Account', 'wca-auth-engine'); ?>
            </h2>
            <p class="wca-modal__subtitle">
                <?php esc_html_e('Join us - it only takes a minute.', 'wca-auth-engine'); ?>
            </p>

            <!-- Error -->
            <div class="wca-alert wca-alert-error" x-show="error" x-text="error" role="alert"></div>

            <!-- Name row -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="wca-field">
                    <label for="wca-reg-first"><?php esc_html_e('First Name', 'wca-auth-engine'); ?></label>
                    <input id="wca-reg-first" type="text" x-model="first_name"
                        placeholder="<?php esc_attr_e('John', 'wca-auth-engine'); ?>" autocomplete="given-name"
                        required>
                </div>
                <div class="wca-field">
                    <label for="wca-reg-last"><?php esc_html_e('Last Name', 'wca-auth-engine'); ?></label>
                    <input id="wca-reg-last" type="text" x-model="last_name"
                        placeholder="<?php esc_attr_e('Doe', 'wca-auth-engine'); ?>" autocomplete="family-name"
                        required>
                </div>
            </div>

            <!-- Email -->
            <div class="wca-field">
                <label for="wca-reg-email"><?php esc_html_e('Email Address', 'wca-auth-engine'); ?></label>
                <input id="wca-reg-email" type="email" x-model="email" placeholder="you@example.com"
                    autocomplete="email" required>
            </div>

            <!-- Phone -->
            <div class="wca-field">
                <label for="wca-reg-phone"><?php esc_html_e('Mobile Number', 'wca-auth-engine'); ?></label>
                <div class="wca-phone-row">
                    <select id="wca-reg-dialcode" x-model="dialCode" class="wca-dialcode-select"
                        aria-label="<?php esc_attr_e('Country code', 'wca-auth-engine'); ?>">
                        <?php foreach (WCA_Constants::get_country_dial_codes() as $code => $label): ?>
                            <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input id="wca-reg-phone" type="tel" x-model="phoneNumber" placeholder="7911123456"
                        autocomplete="tel-national" inputmode="numeric"
                        @input="phoneNumber = phoneNumber.replace(/\D/g, '')"
                        @keypress="if(!/\d/.test($event.key)) $event.preventDefault()" required>
                </div>
                <p style="font-size:0.78rem;color:var(--wca-text-muted);margin-top:5px;line-height:1.3;">
                    <?php esc_html_e('Select your country code then enter your number digits only.', 'wca-auth-engine'); ?>
                </p>
            </div>

            <!-- Password -->
            <div class="wca-field wca-field--password">
                <label for="wca-reg-pass"><?php esc_html_e('Password', 'wca-auth-engine'); ?></label>
                <input id="wca-reg-pass" :type="showPass ? 'text' : 'password'" x-model="password"
                    placeholder="<?php esc_attr_e('Min. 8 chars, 1 uppercase, 1 number', 'wca-auth-engine'); ?>"
                    autocomplete="new-password" required>
                <button type="button" class="wca-btn-reveal" @click="showPass = !showPass"
                    :aria-label="showPass ? '<?php esc_attr_e('Hide password', 'wca-auth-engine'); ?>' : '<?php esc_attr_e('Show password', 'wca-auth-engine'); ?>'">
                    <svg x-show="!showPass" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                    <svg x-show="showPass" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                    </svg>
                </button>
            </div>

            <!-- Submit -->
            <button class="wca-btn wca-btn-primary" @click="submitRegistration" :disabled="loading">
                <span class="wca-spinner" x-show="loading"></span>
                <span
                    x-text="loading ? '<?php esc_html_e('Creating account', 'wca-auth-engine'); ?>' : '<?php esc_html_e('Create Account', 'wca-auth-engine'); ?>'"></span>
            </button>

            <p style="text-align:center;margin-top:20px;font-size:0.85rem;color:var(--wca-text-muted);">
                <?php esc_html_e('Already have an account?', 'wca-auth-engine'); ?>
                <button class="wca-btn-link"
                    data-wca-modal="login"><?php esc_html_e('Sign in', 'wca-auth-engine'); ?></button>
            </p>

            <p style="text-align:center;margin-top:15px;font-size:0.85rem;">
                <a href="<?php echo esc_url(home_url('/')); ?>"
                    style="color:var(--wca-text-muted);text-decoration:none;">
                    &larr; <?php esc_html_e('Return to Shop', 'wca-auth-engine'); ?>
                </a>
            </p>
        </div>

        <!-- -- Step 2: OTP Verification --------------------------------- -->
        <div x-show="step === 'otp'">
            <div class="wca-steps">
                <div class="wca-step done"></div>
                <div class="wca-step active"></div>
                <div class="wca-step"></div>
            </div>

            <h2 class="wca-modal__title"><?php esc_html_e('Verify Your Details', 'wca-auth-engine'); ?></h2>
            <p class="wca-modal__subtitle" x-show="!smsVerified">
                <?php esc_html_e('We sent a 6-digit code to your mobile. Enter it below.', 'wca-auth-engine'); ?>
            </p>
            <p class="wca-modal__subtitle" x-show="smsVerified">
                <?php esc_html_e('Almost there! Check your email inbox and click the verification link.', 'wca-auth-engine'); ?>
            </p>

            <div class="wca-alert wca-alert-error" x-show="error" x-text="error" role="alert"></div>

            <div class="wca-alert wca-alert-info" x-show="emailSent && !emailVerified"
                style="margin-top:10px; display: flex; flex-direction: column; gap: 4px;">
                <span>
                    <?php esc_html_e('Check your email and click the verification link.', 'wca-auth-engine'); ?></span>
                <strong><?php esc_html_e('If you don\'t see it, please check your Spam, Junk or Trash folder.', 'wca-auth-engine'); ?></strong>
            </div>

            <!-- -- State: SMS not yet verified -- -->
            <div x-show="!smsVerified">

                <label style="font-size:0.85rem;font-weight:600;color:var(--wca-text);display:block;margin-bottom:8px;">
                    <?php esc_html_e('Enter the 6-digit SMS code', 'wca-auth-engine'); ?>
                </label>

                <!-- OTP boxes - hidden once SMS is verified -->
                <div class="wca-otp-grid" data-wca-otp-group @wca:otp-complete="verifySms($event.detail.code)">
                    <input data-wca-otp-index="0" type="text" inputmode="numeric" placeholder="" aria-label="Digit 1">
                    <input data-wca-otp-index="1" type="text" inputmode="numeric" placeholder="" aria-label="Digit 2">
                    <input data-wca-otp-index="2" type="text" inputmode="numeric" placeholder="" aria-label="Digit 3">
                    <input data-wca-otp-index="3" type="text" inputmode="numeric" placeholder="" aria-label="Digit 4">
                    <input data-wca-otp-index="4" type="text" inputmode="numeric" placeholder="" aria-label="Digit 5">
                    <input data-wca-otp-index="5" type="text" inputmode="numeric" placeholder="" aria-label="Digit 6">
                </div>

                <div x-show="loading"
                    style="text-align:center;margin-top:12px;color:var(--wca-text-muted);font-size:0.9rem;">
                    <?php esc_html_e('Verifying', 'wca-auth-engine'); ?>
                </div>

                <div class="wca-otp-status" :class="{ 'wca-otp-status--expiring': countdown <= 60 }">
                    <span><?php esc_html_e('Expires in', 'wca-auth-engine'); ?> <span
                            x-text="countdownFormatted"></span></span>
                    <button class="wca-btn-link" @click="resendOtp('sms')" :disabled="resendCooldown > 0">
                        <span x-show="resendCooldown > 0"><?php esc_html_e('Resend in', 'wca-auth-engine'); ?> <span
                                x-text="resendCooldown"></span>s</span>
                        <span
                            x-show="resendCooldown === 0"><?php esc_html_e('Resend SMS code', 'wca-auth-engine'); ?></span>
                    </button>
                </div>

                <p x-show="resendCooldown > 0"
                    style="font-size: 0.78rem; color: var(--wca-text-muted); margin-top: 16px; text-align: center; line-height: 1.4;">
                    <em><?php esc_html_e('Please wait patiently for your code to arrive. Ensure your mobile is on and has signal. If you entered the wrong number, you can go back to update it.', 'wca-auth-engine'); ?></em>
                </p>

            </div><!-- /!smsVerified -->

            <!-- -- State: SMS verified, waiting for email -- -->
            <div x-show="smsVerified && !emailVerified" style="margin-top:8px;">
                <div class="wca-alert wca-alert-success" role="status" style="margin-bottom:16px;">
                     <?php esc_html_e('Mobile number verified!', 'wca-auth-engine'); ?>
                </div>
                <div class="wca-alert wca-alert-info" role="status"
                    style="display:flex;align-items:flex-start;gap:10px;">
                    <span style="font-size:1.4rem;line-height:1;"></span>
                    <div>
                        <strong
                            style="display:block;margin-bottom:4px;"><?php esc_html_e('Check your email', 'wca-auth-engine'); ?></strong>
                        <?php esc_html_e('We sent a verification link to your email address. Click it to complete your registration.', 'wca-auth-engine'); ?>
                    </div>
                </div>
            </div>

            <!-- -- State: Email also verified (edge case - both_verified should trigger 'done') -- -->
            <div x-show="emailVerified" class="wca-alert wca-alert-success" role="status">
                 <?php esc_html_e('Email verified!', 'wca-auth-engine'); ?>
            </div>

            <!-- Go back / edit details -->
            <div style="text-align:center; margin-top:24px;">
                <button class="wca-btn-link" @click="step = 'form'; error = '';"
                    style="font-size: 0.85rem; color: var(--wca-text-muted); text-decoration: underline;">
                    <?php esc_html_e('Need to change your email or phone number?', 'wca-auth-engine'); ?>
                </button>
            </div>
        </div>

        <!-- -- Step 3: Success ------------------------------------------ -->

        <div x-show="step === 'done'" style="text-align:center;padding:20px 0;">
            <div class="wca-success-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <h2 class="wca-modal__title"><?php esc_html_e('Account Created!', 'wca-auth-engine'); ?></h2>
            <p class="wca-modal__subtitle">
                <?php esc_html_e('Welcome aboard. Redirecting you now', 'wca-auth-engine'); ?>
            </p>
        </div>

    </div><!-- /.wca-modal -->
</div><!-- /.wca-modal-backdrop -->