<?php
/**
 * Template: modal-profile-update.php
 * Profile contact-detail update modal - Alpine.js wcaProfileUpdate() component.
 */
defined('ABSPATH') || exit;

if (!is_user_logged_in()) {
    return;
}
?>
<div id="wca-modal-profile-update" class="wca-modal-backdrop" hidden>
    <div class="wca-modal" role="dialog" aria-modal="true" aria-labelledby="wca-profile-update-title"
        x-data="wcaProfileUpdate()" x-init="$nextTick(() => { window._wcaProfileUpdateComponent = $data; })">
        <button class="wca-modal__close" aria-label="<?php esc_attr_e('Close', 'wca-auth-engine'); ?>"
            @click="$el.closest('.wca-modal-backdrop').setAttribute('hidden','')">
            &times;
        </button>

        <!-- -- Step 1: Input form -------------------------------------- -->
        <div x-show="step === 'form'">
            <h2 class="wca-modal__title" id="wca-profile-update-title"
                x-text="verifyMode === 'email' ? '<?php esc_html_e('Verify Your Email', 'wca-auth-engine'); ?>' : (verifyMode === 'phone' ? '<?php esc_html_e('Verify Your Mobile', 'wca-auth-engine'); ?>' : '<?php esc_html_e('Update Contact Details', 'wca-auth-engine'); ?>')">
            </h2>
            <p class="wca-modal__subtitle"
                x-text="verifyMode === 'email' ? '<?php esc_html_e('A 6-digit code will be sent to your email inbox. Enter it on the next screen to confirm your address.', 'wca-auth-engine'); ?>' : (verifyMode === 'phone' ? '<?php esc_html_e('A 6-digit code will be sent by SMS to your mobile. Enter it on the next screen to confirm your number.', 'wca-auth-engine'); ?>' : '<?php esc_html_e('Verify your new email or phone - your current details remain active until confirmed.', 'wca-auth-engine'); ?>')">
            </p>

            <div class="wca-alert wca-alert-error" x-show="error" x-text="error" role="alert"></div>

            <div class="wca-field" x-show="!verifyMode || verifyMode === 'email'">
                <label for="wca-pup-email">
                    <span
                        x-text="verifyMode === 'email' ? '<?php esc_html_e('Email Address', 'wca-auth-engine'); ?>' : '<?php esc_html_e('New Email Address', 'wca-auth-engine'); ?>'"><?php esc_html_e('Email Address', 'wca-auth-engine'); ?></span>
                    <span x-show="!verifyMode"
                        style="color:var(--wca-text-muted);font-weight:400;">(<?php esc_html_e('optional', 'wca-auth-engine'); ?>)</span>
                </label>
                <input id="wca-pup-email" type="email" x-model="newEmail"
                    placeholder="<?php esc_attr_e('you@example.com', 'wca-auth-engine'); ?>" autocomplete="email">
            </div>

            <div class="wca-field" x-show="!verifyMode || verifyMode === 'phone'">
                <label for="wca-pup-phone">
                    <span
                        x-text="verifyMode === 'phone' ? '<?php esc_html_e('Mobile Number', 'wca-auth-engine'); ?>' : '<?php esc_html_e('New Mobile Number', 'wca-auth-engine'); ?>'"><?php esc_html_e('Mobile Number', 'wca-auth-engine'); ?></span>
                    <span x-show="!verifyMode"
                        style="color:var(--wca-text-muted);font-weight:400;">(<?php esc_html_e('optional', 'wca-auth-engine'); ?>)</span>
                </label>
                <div class="wca-phone-row">
                    <select id="wca-pup-dialcode" x-model="dialCode" class="wca-dialcode-select"
                        aria-label="<?php esc_attr_e('Country code', 'wca-auth-engine'); ?>">
                        <?php foreach (WCA_Constants::get_country_dial_codes() as $code => $label): ?>
                            <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input id="wca-pup-phone" type="tel" x-model="phoneNumber" placeholder="7911123456"
                        autocomplete="tel-national" inputmode="numeric"
                        @input="phoneNumber = phoneNumber.replace(/\D/g, '')"
                        @keypress="if(!/\d/.test($event.key)) $event.preventDefault()">
                </div>
                <p style="font-size:0.78rem;color:var(--wca-text-muted);margin-top:5px;line-height:1.3;">
                    <?php esc_html_e('Select your country code then enter your number digits only.', 'wca-auth-engine'); ?>
                </p>
            </div>

            <button class="wca-btn wca-btn-primary" @click="initiateUpdate" :disabled="loading">
                <span class="wca-spinner" x-show="loading"></span>
                <span
                    x-text="loading ? '<?php esc_html_e('Sending', 'wca-auth-engine'); ?>' : (verifyMode ? '<?php esc_html_e('Send Code', 'wca-auth-engine'); ?>' : '<?php esc_html_e('Send Verification Codes', 'wca-auth-engine'); ?>')"></span>
            </button>
        </div>

        <!-- -- Step 2: Verify channels --------------------------------- -->
        <div x-show="step === 'verify'">
            <h2 class="wca-modal__title"
                x-text="channels.length === 1 && channels.includes('email') ? '<?php esc_html_e('Check Your Inbox', 'wca-auth-engine'); ?>' : (channels.length === 1 && channels.includes('sms') ? '<?php esc_html_e('Enter SMS Code', 'wca-auth-engine'); ?>' : '<?php esc_html_e('Enter Verification Codes', 'wca-auth-engine'); ?>')">
            </h2>
            <p class="wca-modal__subtitle"
                x-text="channels.length === 1 && channels.includes('email') ? '<?php esc_html_e('Enter the 6-digit code we sent to your email address.', 'wca-auth-engine'); ?>' : (channels.length === 1 && channels.includes('sms') ? '<?php esc_html_e('Enter the 6-digit code sent by SMS to your mobile number.', 'wca-auth-engine'); ?>' : '<?php esc_html_e('Enter the codes sent to your new contact details.', 'wca-auth-engine'); ?>')">
            </p>

            <div class="wca-alert wca-alert-error" x-show="error" x-text="error" role="alert"></div>

            <!-- Email OTP -->
            <div x-show="channels.includes('email') && !emailVerified">
                <p x-show="channels.length > 1" style="font-weight:600;font-size:0.9rem;margin-bottom:8px;">
                    <?php esc_html_e('Email code', 'wca-auth-engine'); ?>
                </p>
                <div class="wca-otp-grid" data-wca-otp-group
                    @wca:otp-complete="verifyChannel('email', $event.detail.code)">
                    <input data-wca-otp-index="0" type="text" inputmode="numeric" placeholder="" aria-label="Digit 1">
                    <input data-wca-otp-index="1" type="text" inputmode="numeric" placeholder="" aria-label="Digit 2">
                    <input data-wca-otp-index="2" type="text" inputmode="numeric" placeholder="" aria-label="Digit 3">
                    <input data-wca-otp-index="3" type="text" inputmode="numeric" placeholder="" aria-label="Digit 4">
                    <input data-wca-otp-index="4" type="text" inputmode="numeric" placeholder="" aria-label="Digit 5">
                    <input data-wca-otp-index="5" type="text" inputmode="numeric" placeholder="" aria-label="Digit 6">
                </div>
            </div>

            <div class="wca-alert wca-alert-success" x-show="emailVerified && channels.includes('email')">
                 <?php esc_html_e('Email verified!', 'wca-auth-engine'); ?>
            </div>

            <!-- SMS OTP -->
            <div x-show="channels.includes('sms') && !smsVerified" style="margin-top:16px;">
                <p x-show="channels.length > 1" style="font-weight:600;font-size:0.9rem;margin-bottom:8px;">
                    <?php esc_html_e('SMS code', 'wca-auth-engine'); ?>
                </p>
                <div class="wca-otp-grid" data-wca-otp-group
                    @wca:otp-complete="verifyChannel('sms', $event.detail.code)">
                    <input data-wca-otp-index="0" type="text" inputmode="numeric" placeholder="" aria-label="Digit 1">
                    <input data-wca-otp-index="1" type="text" inputmode="numeric" placeholder="" aria-label="Digit 2">
                    <input data-wca-otp-index="2" type="text" inputmode="numeric" placeholder="" aria-label="Digit 3">
                    <input data-wca-otp-index="3" type="text" inputmode="numeric" placeholder="" aria-label="Digit 4">
                    <input data-wca-otp-index="4" type="text" inputmode="numeric" placeholder="" aria-label="Digit 5">
                    <input data-wca-otp-index="5" type="text" inputmode="numeric" placeholder="" aria-label="Digit 6">
                </div>
            </div>

            <div class="wca-alert wca-alert-success" x-show="smsVerified && channels.includes('sms')">
                 <?php esc_html_e('Mobile verified!', 'wca-auth-engine'); ?>
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
                <em
                    x-text="channels.length === 1 && channels.includes('email') ? '<?php esc_html_e('Please wait patiently for your code to arrive in your inbox. Check your spam folder if you do not see it. If you entered the wrong email address, you can go back to update it.', 'wca-auth-engine'); ?>' : (channels.length === 1 && channels.includes('sms') ? '<?php esc_html_e('Please wait patiently for your code to arrive. Ensure your mobile is on and has signal. If you entered the wrong number, you can go back to update it.', 'wca-auth-engine'); ?>' : '<?php esc_html_e('Please wait patiently for your codes to arrive. If you entered the wrong details, you can go back to update them.', 'wca-auth-engine'); ?>')"></em>
            </p>

            <p style="text-align:center;margin-top:16px;">
                <button class="wca-btn-link" @click="step = 'form'">&larr;
                    <?php esc_html_e('Back', 'wca-auth-engine'); ?></button>
            </p>

            <div x-show="loading"
                style="text-align:center;color:var(--wca-text-muted);font-size:0.9rem;margin-top:12px;">
                <?php esc_html_e('Verifying', 'wca-auth-engine'); ?>
            </div>
        </div>

        <!-- -- Step 3: Done --------------------------------------------- -->
        <div x-show="step === 'done'" style="text-align:center;padding:20px 0;">
            <div class="wca-success-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <h2 class="wca-modal__title"><?php esc_html_e('Details Updated!', 'wca-auth-engine'); ?></h2>
            <p class="wca-modal__subtitle"
                x-text="success || '<?php esc_html_e('Your contact details have been updated.', 'wca-auth-engine'); ?>'">
            </p>
        </div>

    </div>
</div>