/**
 * wca-auth-app.js
 * Alpine.js v3 state machine for WCA Auth Engine.
 * Handles: registration, login, OTP verification, profile update modals.
 *
 * All API calls go through the fetchAPI() helper which attaches the WP REST nonce
 * and handles error normalisation.
 *
 * No jQuery dependency. Pure Alpine.js + Fetch API.
 */

// --- Utility: fetch wrapper ---------------------------------------------------

async function wcaFetch(endpoint, body = {}) {
  const cfg = window.wcaConfig || {};
  const url = (cfg.apiBase || '/wp-json/custom-auth/v1') + endpoint;

  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce || '',
      },
      body: JSON.stringify(body),
    });

    // Use text() instead of json() so we can strip any stray output
    // (BOM, PHP notices, debug output from other plugins) that precedes
    // the actual JSON and would otherwise throw a SyntaxError.
    const raw = await res.text();
    const jsonStart = raw.search(/[{[]/);
    const clean = jsonStart > 0 ? raw.substring(jsonStart) : raw;

    let data;
    try {
      data = JSON.parse(clean.trim().replace(/\uFEFF/g, ''));
    } catch (parseErr) {
      console.error('[WCA] JSON parse failed for', endpoint, '| raw:', raw);
      return {
        ok: false,
        status: res.status,
        data: { success: false, message: 'Server returned an invalid response.' },
      };
    }

    return { ok: res.ok, status: res.status, data };
  } catch (err) {
    return {
      ok: false,
      status: 0,
      data: { success: false, message: 'Network error. Please check your connection.' },
    };
  }
}


// --- Utility: reCAPTCHA token -------------------------------------------------

async function wcaGetRecaptchaToken(action = 'submit') {
  const cfg = window.wcaConfig || {};
  if (!cfg.recaptchaKey || !window.grecaptcha) return 'no_recaptcha';
  return new Promise((resolve) => {
    grecaptcha.ready(() => {
      grecaptcha.execute(cfg.recaptchaKey, { action }).then(resolve);
    });
  });
}

// --- Registration Component ---------------------------------------------------

function wcaRegister() {
  const cfg = window.wcaConfig || {};
  const isEmailVerified = cfg.emailVerified && cfg.openModal === 'register';

  return {
    // Steps: form | otp | done
    step: isEmailVerified ? 'otp' : 'form',

    // Form fields
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    dialCode: '+44',
    phoneNumber: '',
    password: '',
    showPass: false,

    // State
    sessionToken: isEmailVerified ? cfg.sessionToken : '',
    loading: false,
    error: '',
    emailSent: isEmailVerified,
    smsSent: isEmailVerified,
    emailVerified: isEmailVerified,
    smsVerified: false,
    resendCooldown: 0,
    resendTimer: null,
    otpTtl: (cfg.otpTtl || 600),
    countdownTimer: null,
    countdown: 0,

    init() {
      this.$watch('dialCode', () => { this.updatePhone(); });
      this.$watch('phoneNumber', () => { this.updatePhone(); });
      this.$watch('phone', (val) => {
        if (!val) return;
        if (val.startsWith('+')) {
          let codes = window.wcaConfig?.dialCodes || ['+44', '+1'];
          // Sort descending by length so longer codes (+1-242) match before shorter (+1)
          codes = [...codes].sort((a, b) => b.length - a.length);
          for (let code of codes) {
            if (val.startsWith(code)) {
              if (this.dialCode !== code) this.dialCode = code;
              const num = val.substring(code.length);
              if (this.phoneNumber !== num) this.phoneNumber = num;
              return;
            }
          }
        }
      });

      const cfg = window.wcaConfig || {};
      if (cfg.isLoggedIn) {
        sessionStorage.removeItem('wca_reg');
        return;
      }

      // Restore session from sessionStorage on page reload (Bug 2 fix).
      if (!this.emailVerified) {
        try {
          const saved = JSON.parse(sessionStorage.getItem('wca_reg') || 'null');
          if (saved && saved.expiresAt > Date.now()) {
            this.sessionToken = saved.token;
            this.phone = saved.phone;
            this.email = saved.email;
            this.first_name = saved.first_name;
            this.last_name = saved.last_name;
            this.emailSent = saved.emailSent;
            this.smsSent = saved.smsSent;
            if (saved.smsVerified) this.smsVerified = saved.smsVerified;
            if (saved.emailVerified) this.emailVerified = saved.emailVerified;
            this.step = 'otp';
            const remaining = Math.max(1, Math.floor((saved.expiresAt - Date.now()) / 1000));
            this.startCountdown(remaining);

            // Smart check: Verify if session was completed in another browser tab
            wcaFetch('/otp/status', { session_token: this.sessionToken }).then(res => {
              if (!res.ok) {
                sessionStorage.removeItem('wca_reg');
                this.step = 'form';
                this.error = 'Your session was completed or expired. Please log in.';
              } else {
                if (res.data.email_verified) this.emailVerified = true;
                if (res.data.sms_verified) this.smsVerified = true;
                if (this.emailVerified && this.smsVerified) {
                  this.step = 'done';
                  this.scheduleRedirect();
                }
              }
            });
          } else {
            sessionStorage.removeItem('wca_reg');
          }
        } catch (_) {
          sessionStorage.removeItem('wca_reg');
        }
      } else {
        this.startCountdown(this.otpTtl);
        this.startResendCooldown(90); // Disable resend from the moment the OTP step is first shown.
      }
    },

    updatePhone() {
      if (this.phoneNumber) {
        this.phone = this.dialCode + this.phoneNumber;
      } else {
        this.phone = '';
      }
    },

    // -- Step 1: Submit registration form ---------------------------------

    async submitRegistration() {
      this.error = '';
      if (!this.validateForm()) return;

      this.loading = true;
      const token = await wcaGetRecaptchaToken('register');

      const res = await wcaFetch('/register/initiate', {
        first_name: this.first_name.trim(),
        last_name: this.last_name.trim(),
        email: this.email.trim(),
        phone: this.phone.trim(),
        password: this.password,
        session_token: this.sessionToken,
        return_url: window.location.pathname + window.location.search, // Fix #14: preserve query string
        recaptcha_token: token,
      });

      this.loading = false;

      if (!res.ok) {
        this.error = res.data?.message || 'Registration failed. Please try again.';
        return;
      }

      this.sessionToken = res.data.session_token;
      this.emailSent = res.data.email_sent;
      this.smsSent = res.data.sms_sent;

      if (res.data.email_verified) this.emailVerified = true;
      if (res.data.sms_verified) this.smsVerified = true;

      if (res.data.both_verified) {
        this.step = 'done';
        this.scheduleRedirect();
        return;
      }

      this.step = 'otp';

      // Persist session so a page reload doesn't lose the OTP window (Bug 2 fix).
      const ttl = res.data.expires_in || this.otpTtl;
      sessionStorage.setItem('wca_reg', JSON.stringify({
        token: this.sessionToken,
        phone: this.phone,
        email: this.email,
        first_name: this.first_name,
        last_name: this.last_name,
        emailSent: this.emailSent,
        smsSent: this.smsSent,
        smsVerified: this.smsVerified,
        emailVerified: this.emailVerified,
        expiresAt: Date.now() + ttl * 1000,
      }));

      this.startCountdown(ttl);
      this.startResendCooldown(90); // Disable resend from the moment OTP step is first shown.
    },

    // -- Verify SMS OTP ----------------------------------------------------

    async verifySms(code) {
      if (code.length < 6) return;
      this.error = '';
      this.loading = true;

      const res = await wcaFetch('/register/verify-sms', {
        session_token: this.sessionToken,
        otp_code: code,
      });

      this.loading = false;

      if (!res.ok) {
        this.error = res.data?.message || 'Invalid code.';
        return;
      }

      this.smsVerified = true;

      try {
        const saved = JSON.parse(sessionStorage.getItem('wca_reg'));
        if (saved) {
          saved.smsVerified = true;
          sessionStorage.setItem('wca_reg', JSON.stringify(saved));
        }
      } catch (e) { }

      if (res.data.both_verified || res.data.user_created) {
        this.step = 'done';
        if (res.data.redirect) this.redirectUrl = res.data.redirect;
        this.scheduleRedirect();
      }
    },

    // -- Resend OTP --------------------------------------------------------

    async resendOtp(channel = 'sms') {
      if (this.resendCooldown > 0) return;
      this.error = '';
      this.loading = true;

      const token = await wcaGetRecaptchaToken('resend');

      const res = await wcaFetch('/otp/resend', {
        session_token: this.sessionToken,
        channel: channel,
        recaptcha_token: token,
      });

      this.loading = false;

      if (!res.ok) {
        this.error = res.data?.message || 'Failed to resend code.';
        return;
      }

      this.startResendCooldown(90);
      this.startCountdown(this.otpTtl);
    },

    // -- Form validation ---------------------------------------------------

    validateForm() {
      if (!this.first_name.trim()) { this.error = 'First name is required.'; return false; }
      if (!this.last_name.trim()) { this.error = 'Last name is required.'; return false; }
      if (!this.email.includes('@')) { this.error = 'Please enter a valid email address.'; return false; }
      if (this.phone.replace(/\D/g, '').length < 10) { this.error = 'Please enter a valid phone number.'; return false; }
      if (this.password.length < 8) { this.error = 'Password must be at least 8 characters.'; return false; }
      if (!/[A-Z]/.test(this.password)) { this.error = 'Password must include an uppercase letter.'; return false; }
      if (!/[0-9]/.test(this.password)) { this.error = 'Password must include a number.'; return false; }
      return true;
    },

    // -- Countdown + timers ------------------------------------------------

    startCountdown(seconds) {
      clearInterval(this.countdownTimer);
      this.countdown = seconds;
      this.countdownTimer = setInterval(() => {
        this.countdown--;
        if (this.countdown <= 0) {
          clearInterval(this.countdownTimer);
          sessionStorage.removeItem('wca_reg'); // Expired - clear persisted session.
          this.error = 'Your session has expired. Please start the registration again.';
        }
      }, 1000);
    },

    startResendCooldown(seconds) {
      this.resendCooldown = seconds;
      clearInterval(this.resendTimer);
      this.resendTimer = setInterval(() => {
        this.resendCooldown--;
        if (this.resendCooldown <= 0) clearInterval(this.resendTimer);
      }, 1000);
    },

    scheduleRedirect() {
      sessionStorage.removeItem('wca_reg'); // Completed - no need to restore session.
      setTimeout(() => {
        if (window.location.pathname.includes('checkout')) {
          window.location.reload();
        } else {
          window.location.href = window.wcaConfig?.myAccountUrl || '/my-account/';
        }
      }, 2000);
    },

    get countdownFormatted() {
      const m = Math.floor(this.countdown / 60);
      const s = String(this.countdown % 60).padStart(2, '0');
      return `${m}:${s}`;
    },
  };
}

// --- Forgot Password Component ----------------------------------------------

function wcaForgotPassword() {
  return {
    step: 'identifier', // identifier | otp | reset | done

    // Tab state
    fpMode: 'email',
    fpDialCode: '+44',
    fpPhoneNumber: '',

    identifier: '',
    otp_code: '',
    password: '',
    showPass: false,
    sessionToken: '',
    channel: '',
    loading: false,
    error: '',
    success: '',
    resendCooldown: 0,
    resendTimer: null,
    countdownTimer: null,
    countdown: 0,

    async initiateReset() {
      this.error = '';
      this.success = '';

      // Build identifier from the active tab.
      let builtIdentifier;
      if (this.fpMode === 'sms') {
        const digits = this.fpPhoneNumber.replace(/\D/g, '');
        if (!digits) { this.error = 'Please enter your mobile number.'; return; }
        builtIdentifier = this.fpDialCode + digits;
      } else {
        if (!this.identifier.trim()) { this.error = 'Please enter your email or username.'; return; }
        builtIdentifier = this.identifier.trim();
      }

      // Store so the OTP step subtitle shows the masked destination.
      this.identifier = builtIdentifier;

      this.loading = true;
      const token = await wcaGetRecaptchaToken('password_forgot');

      const res = await wcaFetch('/password/forgot', {
        identifier: this.identifier.trim(),
        recaptcha_token: token,
      });

      this.loading = false;

      if (!res.ok) {
        this.error = res.data?.message || 'Failed to initiate password reset.';
        return;
      }

      this.sessionToken = res.data.session_token;
      this.channel = res.data.channel;
      this.step = 'otp';
      this.success = res.data.message;
      this.startCountdown(res.data.expires_in || 600);
      this.startResendCooldown(90);
    },

    async verifyOtp(code) {
      if (!code || code.length < 6) return;
      this.error = '';
      this.success = '';
      this.loading = true;

      const res = await wcaFetch('/password/verify-otp', {
        session_token: this.sessionToken,
        otp_code: code,
      });

      this.loading = false;

      if (!res.ok) {
        this.error = res.data?.message || 'Invalid code.';
        return;
      }

      this.step = 'reset';
    },

    async resendOtp() {
      if (this.resendCooldown > 0) return;
      this.error = '';
      this.success = '';
      this.loading = true;

      const token = await wcaGetRecaptchaToken('resend');

      // The backend /password/forgot handles standard rate limiting
      const res = await wcaFetch('/password/forgot', {
        identifier: this.identifier.trim(),
        recaptcha_token: token,
      });

      this.loading = false;

      if (!res.ok) {
        this.error = res.data?.message || 'Failed to resend code.';
        return;
      }

      this.sessionToken = res.data.session_token;
      this.success = 'Code resent successfully.';
      this.startResendCooldown(90);
      this.startCountdown(res.data.expires_in || 600);
    },

    async completeReset() {
      this.error = '';
      if (this.password.length < 8) {
        this.error = 'Password must be at least 8 characters.';
        return;
      }

      this.loading = true;

      const res = await wcaFetch('/password/reset', {
        session_token: this.sessionToken,
        password: this.password,
      });

      this.loading = false;

      if (!res.ok) {
        this.error = res.data?.message || 'Failed to reset password.';
        return;
      }

      this.step = 'done';
    },

    goToLogin() {
      const modal = document.getElementById('wca-modal-forgot-password');
      if (modal) modal.setAttribute('hidden', '');
      const loginModal = document.getElementById('wca-modal-login');
      if (loginModal) loginModal.removeAttribute('hidden');
    },

    startCountdown(seconds) {
      clearInterval(this.countdownTimer);
      this.countdown = seconds;
      this.countdownTimer = setInterval(() => {
        this.countdown--;
        if (this.countdown <= 0) clearInterval(this.countdownTimer);
      }, 1000);
    },

    startResendCooldown(seconds) {
      clearInterval(this.resendTimer);
      this.resendCooldown = seconds;
      this.resendTimer = setInterval(() => {
        this.resendCooldown--;
        if (this.resendCooldown <= 0) clearInterval(this.resendTimer);
      }, 1000);
    }
  };
}

// --- Document Ready bindings ----------------------------------------------------------

function wcaLogin() {
  return {
    // Steps: identifier | auth | otp | done
    step: 'identifier',

    // Tab state: 'email' = username/email field, 'sms' = dial code + phone field
    loginMode: 'email',
    dialCode: '+44',
    phoneNumber: '',   // digits only, no country code

    identifier: '',
    password: '',
    showPass: false,
    otp: '',
    sessionToken: '',
    channel: 'sms',
    userExists: false,
    prefill: null,
    loginNotify: '',
    pendingRedirect: '',

    loading: false,
    error: '',
    resendCooldown: 0,
    resendTimer: null,
    countdown: 0,
    countdownTimer: null,
    otpTtl: (window.wcaConfig?.otpTtl || 600),

    init() {
      // Restore login OTP session on page reload (Bug 2 fix).
      try {
        const saved = JSON.parse(sessionStorage.getItem('wca_login') || 'null');
        if (saved && saved.expiresAt > Date.now()) {
          this.identifier = saved.identifier;
          this.sessionToken = saved.token;
          this.channel = saved.channel;
          this.userExists = true;
          this.step = 'otp';
          const remaining = Math.max(1, Math.floor((saved.expiresAt - Date.now()) / 1000));
          this.startCountdown(remaining);
        } else {
          sessionStorage.removeItem('wca_login');
        }
      } catch (_) {
        sessionStorage.removeItem('wca_login');
      }
    },

    // -- Step 1: Check identifier ------------------------------------------

    async checkIdentifier() {
      this.error = '';

      // Build the canonical identifier depending on which tab is active.
      let builtIdentifier;
      if (this.loginMode === 'sms') {
        const digits = this.phoneNumber.replace(/\D/g, '');
        if (!digits) { this.error = 'Please enter your mobile number.'; return; }
        builtIdentifier = this.dialCode + digits;
      } else {
        if (!this.identifier.trim()) { this.error = 'Please enter your email or username.'; return; }
        builtIdentifier = this.identifier.trim();
      }

      this.loading = true;

      const res = await wcaFetch('/login/check-identifier', {
        identifier: builtIdentifier,
      });

      this.loading = false;

      if (!res.ok) {
        this.error = res.data?.message || 'An error occurred.';
        return;
      }

      if (!res.data.exists) {
        // Fix #11: Switch to register modal with prefill.
        // Sanitise the value BEFORE passing it so no spaces/dots bleed into the register form.
        if (res.data.prefill && res.data.prefill.field === 'phone') {
          res.data.prefill.value = builtIdentifier.replace(/[^\d+]/g, '');
        }
        window.dispatchEvent(new CustomEvent('wca:switch-to-register', {
          detail: { prefill: res.data.prefill },
        }));
        return;
      }

      // Store for OTP step (so we can re-send with the right identifier).
      this.identifier = builtIdentifier;
      this.userExists = true;
      this.step = 'auth';
    },

    // -- Shared: finish a successful login (password or OTP path) ----------

    redirectDestination(serverRedirect) {
      // Fix #9: Locale-safe checkout detection.
      const checkoutPath = new URL(window.wcaConfig?.checkoutUrl || '/checkout/').pathname;
      const fromCheckout = window.location.pathname.startsWith(checkoutPath);
      // Fix #8: Pass redirect_to server-side; fall back to server-provided redirect.
      return fromCheckout
        ? window.wcaConfig?.checkoutUrl
        : (serverRedirect || window.wcaConfig?.myAccountUrl || '/my-account/');
    },

    finishLogin(redirect, notify) {
      this.loginNotify = notify || '';
      this.pendingRedirect = this.redirectDestination(redirect);
      this.step = 'done';

      if (!this.loginNotify) {
        setTimeout(() => {
          window.location.href = this.pendingRedirect;
        }, 1000);
      }
    },

    continueAfterNotify() {
      window.location.href = this.pendingRedirect || window.wcaConfig?.myAccountUrl || '/my-account/';
    },

    // -- Step 2a: Password auth --------------------------------------------

    async loginWithPassword() {
      if (!this.password) { this.error = 'Please enter your password.'; return; }
      this.error = '';
      this.loading = true;

      const res = await wcaFetch('/login/authenticate', {
        identifier: this.identifier.trim(),
        password: this.password,
      });

      this.loading = false;

      if (!res.ok) {
        this.error = res.data?.message || 'Incorrect password.';
        return;
      }

      this.finishLogin(res.data.redirect, res.data.login_notify);
    },

    // -- Step 2b: Request OTP ----------------------------------------------

    async requestOtp() {
      this.error = '';
      this.loading = true;

      const token = await wcaGetRecaptchaToken('login_otp');

      const res = await wcaFetch('/login/send-otp', {
        identifier: this.identifier.trim(),
        recaptcha_token: token,
        channel: this.channel,
      });

      this.loading = false;

      if (!res.ok) {
        this.error = res.data?.message || 'Failed to send code.';
        return;
      }

      this.sessionToken = res.data.session_token;
      this.step = 'otp';

      // Persist OTP session so page reload brings user back here (Bug 2 fix).
      const ttl = res.data.expires_in || this.otpTtl;
      sessionStorage.setItem('wca_login', JSON.stringify({
        token: this.sessionToken,
        identifier: this.identifier.trim(),
        channel: this.channel,
        expiresAt: Date.now() + ttl * 1000,
      }));

      this.startCountdown(ttl);
      this.startResendCooldown(90); // Disable resend from the moment OTP step is first shown.
    },

    // -- Step 3: Verify OTP ------------------------------------------------

    async verifyOtp(code) {
      if (code.length < 6) return;
      this.error = '';
      this.loading = true;

      const res = await wcaFetch('/login/authenticate', {
        identifier: this.identifier.trim(),
        otp_code: code,
        session_token: this.sessionToken,
      });

      this.loading = false;

      if (!res.ok) {
        this.error = res.data?.message || 'Invalid code.';
        return;
      }

      sessionStorage.removeItem('wca_login'); // Logged in - clear persisted session.
      this.finishLogin(res.data.redirect, res.data.login_notify);
    },

    // -- Resend OTP --------------------------------------------------------

    async resendOtp() {
      if (this.resendCooldown > 0) return;
      this.error = '';
      this.loading = true;

      const token = await wcaGetRecaptchaToken('resend');

      const res = await wcaFetch('/otp/resend', {
        session_token: this.sessionToken,
        channel: this.channel,
        recaptcha_token: token,
      });

      this.loading = false;

      if (!res.ok) {
        this.error = res.data?.message || 'Failed to resend code.';
        return;
      }

      this.startResendCooldown(90);
      this.startCountdown(this.otpTtl);
    },

    // -- Timers ------------------------------------------------------------

    startCountdown(seconds) {
      clearInterval(this.countdownTimer);
      this.countdown = seconds;
      this.countdownTimer = setInterval(() => {
        this.countdown--;
        if (this.countdown <= 0) {
          clearInterval(this.countdownTimer);
          sessionStorage.removeItem('wca_login'); // Expired - clear persisted session.
          this.error = 'Your login code has expired. Please request a new one.';
        }
      }, 1000);
    },

    startResendCooldown(s) {
      this.resendCooldown = s;
      clearInterval(this.resendTimer);
      this.resendTimer = setInterval(() => {
        this.resendCooldown--;
        if (this.resendCooldown <= 0) clearInterval(this.resendTimer);
      }, 1000);
    },

    get countdownFormatted() {
      const m = Math.floor(this.countdown / 60);
      const s = String(this.countdown % 60).padStart(2, '0');
      return `${m}:${s}`;
    },
  };
}

// --- Profile Update Component -------------------------------------------------

function wcaProfileUpdate() {
  return {
    // Steps: form | verify | done
    step: 'form',
    verifyMode: '', // '' = update mode, 'email' = verify existing email, 'phone' = verify existing phone
    newEmail: '',
    newPhone: '',
    dialCode: '+44',
    phoneNumber: '',
    channels: [],
    emailOtp: '',
    smsOtp: '',
    emailVerified: false,
    smsVerified: false,
    loading: false,
    error: '',
    success: '',
    resendCooldown: 0,
    resendTimer: null,
    countdown: 0,
    countdownTimer: null,

    init() {
      // Sync logic to handle newPhone being populated externally (e.g. from existing profile)
      this.$watch('newPhone', (val) => {
        if (!val) return;
        // Basic parser to split dial code if it matches known prefixes
        if (val.startsWith('+')) {
          let codes = window.wcaConfig?.dialCodes || ['+44', '+1'];
          codes = [...codes].sort((a, b) => b.length - a.length);
          for (let code of codes) {
            if (val.startsWith(code)) {
              if (this.dialCode !== code) this.dialCode = code;
              const num = val.substring(code.length);
              if (this.phoneNumber !== num) this.phoneNumber = num;
              return; // Matched, done
            }
          }
        }
      });

      this.$watch('dialCode', () => { this.updateNewPhone(); });
      this.$watch('phoneNumber', () => { this.updateNewPhone(); });
    },

    updateNewPhone() {
      if (this.phoneNumber) {
        this.newPhone = this.dialCode + this.phoneNumber;
      } else {
        this.newPhone = '';
      }
    },

    async initiateUpdate() {
      this.error = '';
      if (!this.newEmail && !this.newPhone) {
        this.error = 'Please enter a new email or phone number to update.';
        return;
      }

      this.loading = true;
      const token = await wcaGetRecaptchaToken('profile_update');

      const res = await wcaFetch('/profile/update-initiate', {
        email: this.newEmail || undefined,
        phone: this.newPhone || undefined,
        recaptcha_token: token,
      });

      this.loading = false;

      if (!res.ok) {
        this.error = res.data?.message || 'Failed to initiate update.';
        return;
      }

      this.channels = res.data.channels || [];
      this.step = 'verify';
      this.startCountdown(res.data.expires_in || 600);
      this.startResendCooldown(60);
    },

    async resendOtp() {
      if (this.resendCooldown > 0) return;
      this.initiateUpdate();
    },

    startCountdown(seconds) {
      clearInterval(this.countdownTimer);
      this.countdown = seconds;
      this.countdownTimer = setInterval(() => {
        this.countdown--;
        if (this.countdown <= 0) clearInterval(this.countdownTimer);
      }, 1000);
    },

    startResendCooldown(s) {
      this.resendCooldown = s;
      clearInterval(this.resendTimer);
      this.resendTimer = setInterval(() => {
        this.resendCooldown--;
        if (this.resendCooldown <= 0) clearInterval(this.resendTimer);
      }, 1000);
    },

    get countdownFormatted() {
      const m = Math.floor(this.countdown / 60);
      const s = String(this.countdown % 60).padStart(2, '0');
      return `${m}:${s}`;
    },

    async verifyChannel(channel, code) {
      if (!code || code.length < 6) return;
      this.error = '';
      this.loading = true;

      const res = await wcaFetch('/profile/verify-update', {
        channel: channel,
        otp_code: code,
      });

      this.loading = false;

      if (!res.ok) {
        this.error = res.data?.message || 'Invalid code.';
        return;
      }

      if (channel === 'email') this.emailVerified = true;
      if (channel === 'sms') this.smsVerified = true;

      if (res.data.committed) {
        this.step = 'done';
        this.success = 'Your contact details have been updated successfully.';
        setTimeout(() => {
          window.location.href = res.data.redirect || window.wcaConfig?.myAccountUrl || '/my-account/';
        }, 2000);
      }
    },
  };
}

// --- Modal orchestrator -------------------------------------------------------

document.addEventListener('DOMContentLoaded', () => {
  const cfg = window.wcaConfig || {};
  const openTarget = cfg.openModal || '';

  // Define where the modal should be "locked" (non-dismissible)
  // Fix #9: locale-safe checkout detection instead of pathname.includes('checkout')
  const checkoutPath = new URL(window.wcaConfig?.checkoutUrl || '/checkout/').pathname;
  const onCheckout = window.location.pathname.startsWith(checkoutPath);
  const onMyAccount = window.location.pathname.includes('my-account');
  const isGuest = !document.body.classList.contains('logged-in');

  // add-phone modal is always locked - user must verify before continuing
  const lockModal = onCheckout || (onMyAccount && isGuest) || openTarget === 'add-phone';

  // -- Open requested modal ---------------------------------------------
  if (openTarget) {
    const el = document.getElementById(`wca-modal-${openTarget}`);
    if (el) el.removeAttribute('hidden');
  } else if (onMyAccount && isGuest) {
    // Auto-open login if they land on my-account directly
    const el = document.getElementById('wca-modal-login');
    if (el) el.removeAttribute('hidden');
  }

  // -- Backdrop click - only close if NOT locked ------------------------
  document.querySelectorAll('.wca-modal-backdrop').forEach((backdrop) => {
    backdrop.addEventListener('click', (e) => {
      if (lockModal) return;           // locked on checkout/my-account
      if (e.target === backdrop) backdrop.setAttribute('hidden', '');
    });
  });

  // -- ESC key - only close if NOT locked -------------------------------
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (lockModal) return;               // locked on checkout/my-account
    document.querySelectorAll('.wca-modal-backdrop:not([hidden])').forEach((m) => {
      m.setAttribute('hidden', '');
    });
  });

  // -- Hide close button if locked --------------------------------------
  if (lockModal) {
    document.querySelectorAll('.wca-modal__close').forEach((btn) => {
      btn.style.display = 'none';
    });
  }

  // -- Intercept links with wca_modal in href ---------------------------
  document.addEventListener('click', (e) => {
    const link = e.target.closest('a');
    if (!link) return;

    const href = link.getAttribute('href');
    if (href && href.includes('wca_modal=')) {
      e.preventDefault();
      try {
        const url = new URL(link.href);
        const target = url.searchParams.get('wca_modal');
        if (target) {
          document.querySelectorAll('.wca-modal-backdrop').forEach((m) => {
            m.setAttribute('hidden', '');
          });
          const modal = document.getElementById(`wca-modal-${target}`);
          if (modal) modal.removeAttribute('hidden');
        }
      } catch (err) {
        // ignore malformed URLs
      }
    }
  });

  // -- Hijack WC "Click here to login" link -----------------------------
  const wcLoginToggle = document.querySelector('.woocommerce-form-login-toggle .showlogin');
  if (wcLoginToggle) {
    wcLoginToggle.addEventListener('click', (e) => {
      e.preventDefault();
      const modal = document.getElementById('wca-modal-login');
      if (modal) modal.removeAttribute('hidden');
    });
  }

  // -- data-wca-modal triggers -------------------------------------------
  document.querySelectorAll('[data-wca-modal]').forEach((trigger) => {
    trigger.addEventListener('click', (e) => {
      e.preventDefault();
      const target = trigger.dataset.wcaModal;
      document.querySelectorAll('.wca-modal-backdrop').forEach((m) => {
        m.setAttribute('hidden', '');
      });
      const modal = document.getElementById(`wca-modal-${target}`);
      if (modal) modal.removeAttribute('hidden');
    });
  });

  // -- Cross-modal switch: login → register ------------------------------
  window.addEventListener('wca:switch-to-register', (e) => {
    document.querySelectorAll('.wca-modal-backdrop').forEach((m) => {
      m.setAttribute('hidden', '');
    });
    const regModal = document.getElementById('wca-modal-register');
    if (regModal) regModal.removeAttribute('hidden');
    const prefill = e.detail?.prefill;
    if (prefill && window._wcaRegisterComponent) {
      if (prefill.field === 'email') {
        window._wcaRegisterComponent.email = prefill.value;
      }
      if (prefill.field === 'phone') {
        // Always sanitise: strip spaces, dots, and anything that isn't a digit or leading +
        window._wcaRegisterComponent.phone = String(prefill.value).replace(/[^\d+]/g, '');
      }
    }
  });

  // -- After login on checkout → go back to checkout (not my-account) ---
  // (handled per-component - wcaConfig.checkoutUrl is already passed)

  // -- Intercept WC edit-account form -----------------------------------
  const editFormSelector = cfg.editFormSelector || '#save-account-details';
  const editForm = document.querySelector(editFormSelector)
    || document.querySelector('.woocommerce-EditAccountForm');

  if (editForm) {
    editForm.querySelectorAll('[name="account_email"], [name="billing_phone"]').forEach((field) => {
      field.dataset.original = field.value;
    });

    editForm.addEventListener('submit', async (e) => {
      const emailField = editForm.querySelector('[name="account_email"]');
      const phoneField = editForm.querySelector('[name="billing_phone"]');
      if (!emailField && !phoneField) return;

      const emailChanged = emailField && emailField.value !== emailField.dataset.original;
      const phoneChanged = phoneField && phoneField.value !== phoneField.dataset.original;
      if (!emailChanged && !phoneChanged) return;

      e.preventDefault();
      const modal = document.getElementById('wca-modal-profile-update');
      if (modal) {
        modal.removeAttribute('hidden');
        if (emailChanged && window._wcaProfileUpdateComponent) {
          window._wcaProfileUpdateComponent.newEmail = emailField.value;
        }
        if (phoneChanged && window._wcaProfileUpdateComponent) {
          window._wcaProfileUpdateComponent.newPhone = phoneField.value;
        }
      }
    });
  }
});
