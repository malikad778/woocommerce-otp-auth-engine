<?php
/**
 * Template: modal-login.php
 * Login modal - Alpine.js wcaLogin() component.
 */
defined('ABSPATH') || exit;
?>
<div id="wca-modal-login" class="wca-modal-backdrop" hidden>
    <div class="wca-modal" role="dialog" aria-modal="true" aria-labelledby="wca-login-title" x-data="wcaLogin()">
        <button class="wca-modal__close" aria-label="<?php esc_attr_e('Close', 'wca-auth-engine'); ?>"
            @click="$el.closest('.wca-modal-backdrop').setAttribute('hidden','')">
            &times;
        </button>

        <!-- -- Step 1: Identifier entry --------------------------------- -->
        <div x-show="step === 'identifier'">
            <h2 class="wca-modal__title" id="wca-login-title">
                <?php esc_html_e('Sign In', 'wca-auth-engine'); ?>
            </h2>
            <p class="wca-modal__subtitle">
                <?php esc_html_e('Choose how you\'d like to identify yourself.', 'wca-auth-engine'); ?>
            </p>

            <div class="wca-alert wca-alert-error" x-show="error" x-text="error" role="alert"></div>

            <!-- Mode tabs -->
            <div class="wca-login-tabs" role="tablist">
                <button type="button" role="tab" :class="{ active: loginMode === 'email' }"
                    @click="loginMode = 'email'; error = ''" :aria-selected="loginMode === 'email'" id="wca-tab-email"
                    aria-controls="wca-panel-email">
                    <?php esc_html_e('Email / Username', 'wca-auth-engine'); ?>
                </button>
                <button type="button" role="tab" :class="{ active: loginMode === 'sms' }"
                    @click="loginMode = 'sms'; error = ''" :aria-selected="loginMode === 'sms'" id="wca-tab-sms"
                    aria-controls="wca-panel-sms">
                     <?php esc_html_e('Mobile Number', 'wca-auth-engine'); ?>
                </button>
            </div>

            <!-- Panel A: Email / Username -->
            <div id="wca-panel-email" role="tabpanel" aria-labelledby="wca-tab-email" x-show="loginMode === 'email'"
                class="wca-tab-panel">
                <div class="wca-field">
                    <label for="wca-login-id"><?php esc_html_e('Email or Username', 'wca-auth-engine'); ?></label>
                    <input id="wca-login-id" type="text" x-model="identifier"
                        placeholder="<?php esc_attr_e('you@example.com or johndoe', 'wca-auth-engine'); ?>"
                        autocomplete="username email" @keydown.enter="checkIdentifier">
                </div>
            </div>

            <!-- Panel B: Mobile number -->
            <div id="wca-panel-sms" role="tabpanel" aria-labelledby="wca-tab-sms" x-show="loginMode === 'sms'"
                class="wca-tab-panel">
                <div class="wca-field">
                    <label for="wca-login-phone"><?php esc_html_e('Mobile Number', 'wca-auth-engine'); ?></label>
                    <div class="wca-phone-row">
                        <select id="wca-login-dialcode" x-model="dialCode" class="wca-dialcode-select"
                            aria-label="<?php esc_attr_e('Country code', 'wca-auth-engine'); ?>">
                            <?php foreach (WCA_Constants::get_country_dial_codes() as $code => $label): ?>
                                <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input id="wca-login-phone" type="tel" x-model="phoneNumber" placeholder="7911123456"
                            inputmode="numeric" autocomplete="tel-national"
                            @input="phoneNumber = phoneNumber.replace(/\D/g, '')"
                            @keypress="if(!/\d/.test($event.key)) $event.preventDefault()"
                            @keydown.enter="checkIdentifier">
                    </div>
                    <p style="font-size:0.78rem;color:var(--wca-text-muted);margin-top:5px;line-height:1.3;">
                        <?php esc_html_e('Select your country code then enter your number digits only.', 'wca-auth-engine'); ?>
                    </p>
                </div>
            </div>

            <button class="wca-btn wca-btn-primary" @click="checkIdentifier" :disabled="loading">
                <span class="wca-spinner" x-show="loading"></span>
                <span
                    x-text="loading ? '<?php esc_html_e('Checking', 'wca-auth-engine'); ?>' : '<?php esc_html_e('Continue', 'wca-auth-engine'); ?>'"></span>
            </button>

            <p style="text-align:center;margin-top:20px;font-size:0.85rem;color:var(--wca-text-muted);">
                <?php esc_html_e('New here?', 'wca-auth-engine'); ?>
                <button class="wca-btn-link"
                    data-wca-modal="register"><?php esc_html_e('Create account', 'wca-auth-engine'); ?></button>
            </p>

            <?php $wca_announcement = get_site_option('wca_global_announcement', ''); ?>
            <?php if ($wca_announcement !== ''): ?>
                <div class="wca-alert wca-alert-info wca-announcement-banner" style="text-align:left;margin-top:16px;">
                    <?php echo wp_kses_post($wca_announcement); ?>
                </div>
            <?php endif; ?>

            <p style="text-align:center;margin-top:15px;font-size:0.85rem;">
                <a href="<?php echo esc_url(home_url('/')); ?>"
                    style="color:var(--wca-text-muted);text-decoration:none;">
                    &larr; <?php esc_html_e('Return to Shop', 'wca-auth-engine'); ?>
                </a>
            </p>
        </div>

        <!-- -- Step 2: Auth method choice ------------------------------- -->
        <div x-show="step === 'auth'">
            <h2 class="wca-modal__title"><?php esc_html_e('Welcome Back', 'wca-auth-engine'); ?></h2>
            <p class="wca-modal__subtitle">
                <?php esc_html_e('Choose how you\'d like to sign in.', 'wca-auth-engine'); ?>
            </p>

            <div class="wca-alert wca-alert-error" x-show="error" x-text="error" role="alert"></div>

            <!-- Password field -->
            <div class="wca-field wca-field--password">
                <div style="display:flex; justify-content:space-between; align-items:baseline;">
                    <label for="wca-login-pass"><?php esc_html_e('Password', 'wca-auth-engine'); ?></label>
                    <button type="button" data-wca-modal="forgot-password"
                        style="font-size:0.85rem; color:var(--wca-primary); text-decoration:none; background:none; border:none; padding:0; cursor:pointer;">
                        <?php esc_html_e('Forgot?', 'wca-auth-engine'); ?>
                    </button>
                </div>
                <input id="wca-login-pass" :type="showPass ? 'text' : 'password'" x-model="password"
                    placeholder="<?php esc_attr_e('Your password', 'wca-auth-engine'); ?>"
                    autocomplete="current-password" @keydown.enter="loginWithPassword">
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
            </div>

            <button class="wca-btn wca-btn-primary" @click="loginWithPassword" :disabled="loading">
                <span class="wca-spinner" x-show="loading"></span>
                <span
                    x-text="loading ? '<?php esc_html_e('Signing in', 'wca-auth-engine'); ?>' : '<?php esc_html_e('Sign In with Password', 'wca-auth-engine'); ?>'"></span>
            </button>

            <div class="wca-divider"><?php esc_html_e('or', 'wca-auth-engine'); ?></div>

            <!-- One-click OTP channel buttons -->
            <div class="wca-otp-actions">
                <button class="wca-otp-action-btn" @click="channel = 'sms'; requestOtp()" :disabled="loading">
                    <span class="wca-otp-action-btn__icon"></span>
                    <span class="wca-otp-action-btn__label">
                        <strong><?php esc_html_e('Send SMS Code', 'wca-auth-engine'); ?></strong>
                        <small><?php esc_html_e('to your registered mobile', 'wca-auth-engine'); ?></small>
                    </span>
                    <span class="wca-spinner" x-show="loading && channel === 'sms'"
                        style="border-top-color:var(--wca-primary);border-color:rgba(0,0,0,0.1);flex-shrink:0;"></span>
                    <svg x-show="!loading || channel !== 'sms'" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 24 24" stroke="currentColor"
                        style="width:16px;height:16px;flex-shrink:0;opacity:0.4;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
                <button class="wca-otp-action-btn" @click="channel = 'email'; requestOtp()" :disabled="loading">
                    <span class="wca-otp-action-btn__icon"></span>
                    <span class="wca-otp-action-btn__label">
                        <strong><?php esc_html_e('Email me a Code', 'wca-auth-engine'); ?></strong>
                        <small><?php esc_html_e('to your registered email address', 'wca-auth-engine'); ?></small>
                    </span>
                    <span class="wca-spinner" x-show="loading && channel === 'email'"
                        style="border-top-color:var(--wca-primary);border-color:rgba(0,0,0,0.1);flex-shrink:0;"></span>
                    <svg x-show="!loading || channel !== 'email'" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 24 24" stroke="currentColor"
                        style="width:16px;height:16px;flex-shrink:0;opacity:0.4;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            </div>

            <p style="text-align:center;margin-top:16px;">
                <button class="wca-btn-link" @click="step = 'identifier'">&larr;
                    <?php esc_html_e('Back', 'wca-auth-engine'); ?></button>
            </p>
        </div>

        <!-- -- Step 3: OTP entry ----------------------------------------- -->
        <div x-show="step === 'otp'">
            <h2 class="wca-modal__title"><?php esc_html_e('Enter Your Code', 'wca-auth-engine'); ?></h2>
            <p class="wca-modal__subtitle">
                <span
                    x-text="channel === 'sms' ? '<?php esc_html_e('We sent a 6-digit code to your mobile.', 'wca-auth-engine'); ?>' : '<?php esc_html_e('We sent a 6-digit code to your email.', 'wca-auth-engine'); ?>'"></span>
                <br x-show="channel === 'email'">
                <small x-show="channel === 'email'" style="color:var(--wca-text-muted);">
                    <?php esc_html_e('Don\'t see it? Check your', 'wca-auth-engine'); ?>
                    <strong><?php esc_html_e('Spam, Junk or Trash', 'wca-auth-engine'); ?></strong>
                    <?php esc_html_e('folder.', 'wca-auth-engine'); ?>
                </small>
            </p>

            <div class="wca-alert wca-alert-error" x-show="error" x-text="error" role="alert"></div>

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
                <button class="wca-btn-link" @click="step = 'auth'">&larr;
                    <?php esc_html_e('Try another method', 'wca-auth-engine'); ?></button>
            </p>
        </div>

        <!-- -- Step 4: Success ------------------------------------------ -->
        <div x-show="step === 'done'" style="text-align:center;padding:20px 0;">
            <div class="wca-success-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <h2 class="wca-modal__title"><?php esc_html_e('Signed In!', 'wca-auth-engine'); ?></h2>
            <p class="wca-modal__subtitle" x-show="!loginNotify">
                <?php esc_html_e('Redirecting you now', 'wca-auth-engine'); ?></p>

            <div class="wca-alert wca-alert-info" style="text-align:left;" x-show="loginNotify" x-html="loginNotify">
            </div>

            <button class="wca-btn wca-btn-primary" x-show="loginNotify" @click="continueAfterNotify"
                style="margin-top:10px;">
                <?php esc_html_e('Continue', 'wca-auth-engine'); ?>
            </button>
        </div>

    </div><!-- /.wca-modal -->
</div><!-- /.wca-modal-backdrop -->