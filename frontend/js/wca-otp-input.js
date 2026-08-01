/**
 * wca-otp-input.js
 * Handles the 6-digit OTP input boxes.
 * Features: auto-advance on digit, backspace navigation, paste detection.
 *
 * Usage: Attach data-wca-otp-group to the container. Each input inside
 * receives data-wca-otp-index="0" through "5".
 *
 * On completion, fires CustomEvent 'wca:otp-complete' on the container
 * with detail.code = the assembled 6-digit string.
 */

(function () {
  'use strict';

  function initOtpGroup(container) {
    const inputs = Array.from(container.querySelectorAll('[data-wca-otp-index]'));
    if (inputs.length !== 6) return;

    // -- Paste handler on any box -----------------------------------------

    container.addEventListener('paste', (e) => {
      e.preventDefault();
      const text  = (e.clipboardData || window.clipboardData).getData('text');
      const digits = text.replace(/\D/g, '').slice(0, 6);

      digits.split('').forEach((d, i) => {
        if (inputs[i]) inputs[i].value = d;
      });

      // Focus last filled box.
      const last = inputs[Math.min(digits.length, 5)];
      if (last) last.focus();

      dispatchIfComplete(container, inputs);
    });

    // -- Per-input event handlers -----------------------------------------

    inputs.forEach((input, idx) => {
      // Only allow single digit.
      input.setAttribute('maxlength', '1');
      input.setAttribute('inputmode', 'numeric');
      input.setAttribute('pattern',   '[0-9]');
      input.setAttribute('autocomplete', idx === 0 ? 'one-time-code' : 'off');

      input.addEventListener('input', (e) => {
        const val = input.value.replace(/\D/g, '');
        input.value = val.slice(-1); // Keep only last digit (handles replace).

        if (input.value && idx < 5) {
          inputs[idx + 1].focus();
        }

        dispatchIfComplete(container, inputs);
      });

      input.addEventListener('keydown', (e) => {
        // Backspace: clear current and move back.
        if (e.key === 'Backspace') {
          if (input.value === '' && idx > 0) {
            inputs[idx - 1].value = '';
            inputs[idx - 1].focus();
          }
        }

        // Arrow navigation.
        if (e.key === 'ArrowLeft'  && idx > 0) { e.preventDefault(); inputs[idx - 1].focus(); }
        if (e.key === 'ArrowRight' && idx < 5) { e.preventDefault(); inputs[idx + 1].focus(); }
      });

      // Select all on focus for easy replacement.
      input.addEventListener('focus', () => input.select());
    });
  }

  function dispatchIfComplete(container, inputs) {
    const code = inputs.map((i) => i.value).join('');
    if (code.length === 6 && /^\d{6}$/.test(code)) {
      container.dispatchEvent(new CustomEvent('wca:otp-complete', {
        bubbles: true,
        detail: { code },
      }));
    }
  }

  // -- Auto-init all groups on DOM ready -----------------------------------

  function init() {
    document.querySelectorAll('[data-wca-otp-group]').forEach(initOtpGroup);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Re-init on dynamic modal insertions (Alpine.js x-if creates new DOM).
  const observer = new MutationObserver((mutations) => {
    mutations.forEach((m) => {
      m.addedNodes.forEach((node) => {
        if (node.nodeType !== 1) return;
        const groups = node.querySelectorAll ? node.querySelectorAll('[data-wca-otp-group]') : [];
        groups.forEach(initOtpGroup);
        if (node.matches && node.matches('[data-wca-otp-group]')) initOtpGroup(node);
      });
    });
  });

  observer.observe(document.body, { childList: true, subtree: true });
})();
