# WCA Auth Engine

[![Tests](https://github.com/malikad778/woocommerce-otp-auth-engine/actions/workflows/tests.yml/badge.svg)](https://github.com/malikad778/woocommerce-otp-auth-engine/actions/workflows/tests.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress: >= 6.0](https://img.shields.io/badge/WordPress-%3E%3D%206.0-blue)](https://wordpress.org)
[![WooCommerce: >= 7.0](https://img.shields.io/badge/WooCommerce-%3E%3D%207.0-purple)](https://woocommerce.com)
[![PHP: >= 8.1](https://img.shields.io/badge/PHP-%3E%3D%208.1-777BB4.svg)](https://php.net)

**WCA Auth Engine** is a high-performance, decoupled authentication and registration system built specifically for **WooCommerce** and **WordPress** (Single-Site & Multisite).

It replaces native WordPress/WooCommerce login and registration forms with an optimized, REST-API-driven pipeline featuring **pre-database registration gating**, **multi-identifier authentication** (Email, Username, or Phone), **dual OTP verification** (Native Email + TextMagic SMS), and **WooCommerce checkout field locking**.

---

## 🚀 Core Architectural Features

### 1. Pre-Database Registration Pipeline (`WCA_Registration_Pipeline`)
Standard WordPress registration immediately inserts unverified users into `wp_users`. WCA Auth Engine uses a decoupled **3-Phase Transient Pipeline**:

* **Phase 1 (Initiate):** Validates payload, hashes passwords immediately, and stores session data inside temporary encrypted transients. **Zero database user rows are created.**
* **Phase 2 (Verify):** User completes verification via Email magic link/token or SMS OTP code. Transient flags (`email_verified`, `sms_verified`) are updated.
* **Phase 3 (Complete):** Once verified, `wp_create_user()` is called, user meta (`billing_phone`, `billing_phone_verified`, `billing_email_verified`) is attached, authentication cookies are set, and transients are purged.

### 2. Multi-Identifier Auth (`WCA_Identifier_Resolver`)
Users can log in or initiate passwordless verification using any valid identifier:
* Email Address (`user@example.com`)
* Username (`john_doe`)
* Phone Number (`+1234567890`)

### 3. Dual OTP Engine (`WCA_OTP_Dispatcher`)
* **Native Email Verification:** HTML email templates rendered via `WCA_Template_Engine` and sent through native `wp_mail()`.
* **SMS Verification:** Built-in TextMagic API integration (`WCA_TextMagic_Client`) with customizable SMS message templates.
* **Flexible Channels:** Users can choose between SMS and Email OTP or switch channels seamlessly during verification.

### 4. WooCommerce Checkout Integration (`WCA_Checkout_Guard` & `WCA_Checkout_Field_Locker`)
* **Field Locking:** Locks `billing_phone` and `billing_email` on the WooCommerce Checkout page to match the authenticated user's verified contact details, preventing spoofed checkouts.
* **Checkout Enforcement:** Enforces valid account status and phone/email verification prior to order placement.

### 5. Account Profile & Reverification Guard (`WCA_Profile_Update_Manager`)
* Intercepts WooCommerce "Edit Account" and billing profile fields.
* Updating phone numbers or email addresses automatically flags the account for OTP re-verification before saving changes to user meta.

### 6. Decoupled Alpine.js Frontend Modals
Lightweight, responsive AJAX/REST API frontend modals (`frontend/templates/`) powered by Alpine.js:
* **Modal Register** (`modal-register.php`)
* **Modal Login** (`modal-login.php`)
* **Modal OTP Verification** (`modal-otp-verify.php`)
* **Modal Password Reset** (`modal-forgot-password.php`)
* **Modal Profile Update** (`modal-profile-update.php`)
* **Modal Add Phone** (`modal-add-phone.php` for legacy users)

### 7. User Table Tools & Admin Panel (`WCA_User_Table_Columns` & `WCA_Admin_User_Tools`)
* Adds **Phone**, **Phone Status**, and **Email Status** columns directly to WordPress User tables (`wp-admin/users.php`).
* Provides network-wide options management for WordPress Multisite networks (`WCA_Network_Admin`).
* Built-in log viewer (`WCA_Log_Viewer`) and email test controller (`WCA_Email_Test_Controller`).

---

## 🛠️ Codebase Structure

```text
wca-auth-engine/
├── wca-auth-engine.php             # Main plugin bootstrap & hook registration
├── uninstall.php                   # Cleanup script on plugin deletion
├── email-templates/                # Responsive HTML email templates
│   ├── login-otp.html
│   ├── password-reset.html
│   ├── profile-update-verify.html
│   └── registration-verify.html
├── frontend/                       # Decoupled frontend assets & templates
│   ├── css/
│   │   └── wca-auth.css            # Styles for authentication modals
│   ├── js/
│   │   ├── wca-auth-app.js         # Alpine.js application controller
│   │   ├── wca-otp-input.js        # Auto-focusing OTP input handler
│   │   └── wca-admin-settings.js   # Admin dashboard JS
│   └── templates/                  # PHP Modal view templates
│       ├── modal-add-phone.php
│       ├── modal-forgot-password.php
│       ├── modal-login.php
│       ├── modal-otp-verify.php
│       ├── modal-profile-update.php
│       ├── modal-register.php
│       └── modal-reverify-email.php
└── includes/                       # Core PHP classes & modules
    ├── class-wca-activator.php      # Activation setup & DB migrations
    ├── class-wca-autoloader.php     # Class autoloader
    ├── class-wca-constants.php      # Namespace, TTL, and config constants
    ├── class-wca-deactivator.php    # Deactivation cleanup
    ├── admin/                       # Admin screens & user table integration
    │   ├── class-wca-admin-user-tools.php
    │   ├── class-wca-email-test-controller.php
    │   ├── class-wca-log-viewer.php
    │   ├── class-wca-login-notify.php
    │   ├── class-wca-network-admin.php
    │   ├── class-wca-settings.php
    │   └── class-wca-user-table-columns.php
    ├── api/                         # REST API endpoints (custom-auth/v1)
    │   ├── class-wca-api-router.php
    │   └── endpoints/
    │       ├── class-wca-endpoint-add-phone.php
    │       ├── class-wca-endpoint-login.php
    │       ├── class-wca-endpoint-otp.php
    │       ├── class-wca-endpoint-password.php
    │       ├── class-wca-endpoint-profile.php
    │       └── class-wca-endpoint-register.php
    ├── auth/                        # Authentication core logic
    │   ├── class-wca-auth-engine.php
    │   ├── class-wca-identifier-resolver.php
    │   └── class-wca-session-manager.php
    ├── checkout/                    # WooCommerce checkout field locking & protection
    │   ├── class-wca-checkout-field-locker.php
    │   └── class-wca-checkout-guard.php
    ├── email/                       # Email dispatch & template engine
    │   ├── class-wca-email-client.php
    │   └── class-wca-template-engine.php
    ├── integrations/                # External integrations
    │   └── class-wca-textmagic-client.php
    ├── logging/                     # Audit logging
    │   └── class-wca-logger.php
    ├── migration/                   # User account migration tools
    │   └── class-wca-account-migrator.php
    ├── otp/                         # OTP generation, dispatching, and validation
    │   ├── class-wca-otp-dispatcher.php
    │   ├── class-wca-otp-generator.php
    │   └── class-wca-otp-validator.php
    ├── profile/                     # Profile verification & updating
    │   ├── class-wca-profile-update-manager.php
    │   └── class-wca-profile-verifier.php
    ├── registration/                # Pre-database registration pipeline
    │   ├── class-wca-registration-completer.php
    │   ├── class-wca-registration-pipeline.php
    │   └── class-wca-registration-validator.php
    ├── security/                    # Security guards & rate limiters
    │   ├── class-wca-rate-limiter.php
    │   ├── class-wca-recaptcha.php
    │   ├── class-wca-registration-guard.php
    │   └── class-wca-sanitizer.php
    └── transient/                   # Encrypted transient store & cleanup janitor
        ├── class-wca-transient-janitor.php
        └── class-wca-transient-store.php
```

---

## 📡 REST API Routes

All endpoints are registered under namespace `custom-auth/v1`:

### 📝 Registration
* `POST /wp-json/custom-auth/v1/register/initiate` - Initiates registration, validates input, stores transient payload, dispatches OTP.
* `GET, POST /wp-json/custom-auth/v1/register/verify-email` - Verifies email magic token.
* `POST /wp-json/custom-auth/v1/register/verify-sms` - Verifies SMS OTP code.
* `POST /wp-json/custom-auth/v1/register/complete` - Finalizes account creation into `wp_users` and sets auth cookies.

### 🔑 Authentication
* `POST /wp-json/custom-auth/v1/login/check-identifier` - Resolves identifier (email, username, phone).
* `POST /wp-json/custom-auth/v1/login/send-otp` - Dispatches OTP for passwordless login.
* `POST /wp-json/custom-auth/v1/login/authenticate` - Authenticates user via password or OTP code.

### 🔓 Password Reset & Profile
* `POST /wp-json/custom-auth/v1/password/forgot` - Initiates password reset flow.
* `POST /wp-json/custom-auth/v1/password/verify-otp` - Verifies reset OTP code.
* `POST /wp-json/custom-auth/v1/password/reset` - Sets new password.
* `POST /wp-json/custom-auth/v1/profile/update-initiate` - Triggers contact update with re-verification.
* `POST /wp-json/custom-auth/v1/profile/verify-update` - Verifies profile update OTP code.

---

## ⚙️ Requirements & Installation

### System Requirements
* **WordPress:** 6.0 or higher
* **WooCommerce:** 7.0 or higher
* **PHP:** 8.1 or higher

### Installation
1. Clone or copy the plugin to your WordPress plugins directory:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/malikad778/woocommerce-otp-auth-engine.git wca-auth-engine
   ```
2. Activate **WCA Auth Engine** via **Plugins > Installed Plugins** (or **Network Activate** on Multisite).
3. Configure TextMagic credentials, Email settings, and custom options in **WCA Auth Engine > Settings**.

---

## 📄 License

Distributed under the **GPLv2 or later** License. See [LICENSE](LICENSE) for more information.

---

## 👨‍💻 Author

Developed with ❤️ by **[Adnan Haider](https://codebyadnan.tech/)**.
