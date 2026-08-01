/**
 * wca-admin-settings.js
 * Network admin JS for the WCA Auth Engine settings page:
 *   - Email Templates tab: live preview (iframe srcdoc) + "Send Test Email" AJAX.
 *   - Login Notify tab: user search autocomplete + get/save/clear AJAX.
 *
 * Vanilla JS only - no dependency on Alpine/jQuery, since site-level plugin
 * assets aren't reliably available in the network admin context.
 */

(function () {
  'use strict';

  var cfg = window.wcaAdminSettings || {};

  // --- Shared: {{ params.KEY }} substitution (mirrors WCA_Template_Engine::render) ---

  function renderTemplate(str, params) {
    if (!str) return '';
    return String(str).replace(/\{\{\s*params\.([A-Z0-9_]+)\s*\}\}/g, function (match, key) {
      return Object.prototype.hasOwnProperty.call(params, key) ? String(params[key]) : '';
    });
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  // Strips UTF-8 BOM (\uFEFF) and any stray PHP debug output that other
  // plugins or wp-config.php may inject before our JSON response.
  function safeJson(r) {
    // Guard: r must be a proper fetch Response. Service workers, browser
    // extensions or patched fetch() implementations can return objects that
    // don't have a .text() method, causing the entire promise chain to reject
    // and showing "Network error." even though the server responded correctly.
    if (!r || typeof r.text !== 'function') {
      console.error('[WCA] safeJson: unexpected response object:', r);
      return Promise.resolve(null);
    }
    return r.text().then(function (raw) {
      // Find the first '{' or '[' - everything before it is garbage output.
      var start = raw.search(/[{[]/);
      if (start > 0) {
        console.warn('[WCA] Stripped ' + start + ' chars of unexpected output before JSON:', JSON.stringify(raw.substring(0, start)));
        raw = raw.substring(start);
      }
      // Also strip trailing whitespace/null bytes after the JSON.
      raw = raw.trim().replace(/\uFEFF/g, '');
      try {
        return JSON.parse(raw);
      } catch (e) {
        console.error('[WCA] JSON parse failed. Raw response:', raw);
        return null;
      }
    }).catch(function (e) {
      // r.text() itself can throw if the body stream was already consumed
      // (e.g. by a service worker) or if the response was aborted.
      console.error('[WCA] safeJson r.text() failed:', e);
      return null;
    });
  }

  function ajaxGet(action, nonce, params) {
    var url = new URL(cfg.ajaxUrl, window.location.href);
    url.searchParams.set('action', action);
    url.searchParams.set('_ajax_nonce', nonce);
    Object.keys(params || {}).forEach(function (k) {
      url.searchParams.set(k, params[k]);
    });
    return fetch(url.toString(), { credentials: 'same-origin' }).then(safeJson);
  }

  function ajaxPost(action, nonce, params) {
    var formData = new FormData();
    formData.append('action', action);
    formData.append('_ajax_nonce', nonce);
    Object.keys(params || {}).forEach(function (k) {
      formData.append(k, params[k] == null ? '' : params[k]);
    });
    return fetch(cfg.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData,
      // Prevent service workers and browser caches from intercepting
      // admin-ajax.php requests, which can return stale/opaque responses
      // that break the promise chain and show false "Network error." messages.
      cache: 'no-store'
    }).then(safeJson);
  }


  document.addEventListener('DOMContentLoaded', function () {
    initEmailPreview();
    initTestSend();
    initLoginNotify();
  });

  // --- Email Templates: live preview -------------------------------------

  function initEmailPreview() {
    var editors = document.querySelectorAll('[data-wca-body-editor]');
    if (!editors.length) return;

    var sampleParams = cfg.sampleParams || {};

    editors.forEach(function (bodyField) {
      var templateKey = bodyField.getAttribute('data-template-key');
      var wrapper = bodyField.closest('.wca-template-selector');
      if (!wrapper) return;

      var frame = wrapper.querySelector('[data-wca-preview-frame]');
      var subjectPreview = wrapper.querySelector('[data-wca-subject-preview]');
      var subjectField = document.querySelector('[data-wca-subject-key="' + templateKey + '"]');
      var params = sampleParams[templateKey] || {};

      var timer = null;
      function update() {
        clearTimeout(timer);
        timer = setTimeout(function () {
          if (frame) frame.srcdoc = renderTemplate(bodyField.value, params);
          if (subjectPreview) subjectPreview.textContent = renderTemplate(subjectField ? subjectField.value : '', params);
        }, 400);
      }

      bodyField.addEventListener('input', update);
      if (subjectField) subjectField.addEventListener('input', update);
      update();
    });
  }

  // --- Email Templates: Send Test Email ----------------------------------

  function initTestSend() {
    var rows = document.querySelectorAll('.wca-test-send-row');
    if (!rows.length) return;

    rows.forEach(function (row) {
      var templateKey = row.getAttribute('data-template-key');
      var btn = row.querySelector('.wca-test-send-btn');
      var emailInput = row.querySelector('.wca-test-send-email');
      var siteSelect = row.querySelector('.wca-test-send-site');
      var resultEl = row.querySelector('.wca-test-send-result');
      if (!btn) return;

      btn.addEventListener('click', function () {
        var email = (emailInput.value || '').trim();
        if (!email) {
          resultEl.style.color = '#c00';
          resultEl.textContent = 'Enter an email address first.';
          return;
        }

        var bodyField = document.querySelector('[data-wca-body-editor][data-template-key="' + templateKey + '"]');
        var subjectField = document.querySelector('[data-wca-subject-key="' + templateKey + '"]');

        btn.disabled = true;
        resultEl.style.color = '#666';
        resultEl.textContent = 'Sending…';

        ajaxPost('wca_test_email', cfg.testEmailNonce, {
          email: email,
          template_key: templateKey,
          blog_id: siteSelect ? siteSelect.value : '',
          subject_draft: subjectField ? subjectField.value : '',
          body_draft: bodyField ? bodyField.value : ''
        }).then(function (res) {
          btn.disabled = false;
          if (res && res.success) {
            resultEl.style.color = '#0a7a0a';
            resultEl.textContent = (res.data && res.data.message) || 'Sent!';
          } else {
            resultEl.style.color = '#c00';
            resultEl.textContent = (res && res.data && res.data.message) || 'Failed to send.';
          }
        }).catch(function (err) {
          // Log the real error so it is visible in DevTools Console.
          // Common causes: service worker returning a non-standard Response,
          // browser extension patching fetch(), or a JS error thrown inside
          // the .then() handler above.
          console.error('[WCA] Test-send promise chain error:', err);
          btn.disabled = false;
          resultEl.style.color = '#c00';
          resultEl.textContent = 'Network error. Check browser console for details.';
        });
      });
    });
  }

  // --- Login Notify panel -------------------------------------------------

  function initLoginNotify() {
    var searchInput = document.getElementById('wca-notify-user-search');
    if (!searchInput) return; // Not on this tab.

    var resultsBox = document.getElementById('wca-notify-search-results');
    var editor = document.getElementById('wca-notify-editor');
    var nameEl = document.getElementById('wca-notify-selected-name');
    var emailEl = document.getElementById('wca-notify-selected-email');
    var statusBadge = document.getElementById('wca-notify-selected-status');
    var messageEl = document.getElementById('wca-notify-message');
    var userIdEl = document.getElementById('wca-notify-user-id');
    var saveBtn = document.getElementById('wca-notify-save');
    var clearBtn = document.getElementById('wca-notify-clear');
    var statusEl = document.getElementById('wca-notify-status');
    var nonce = cfg.loginNotifyNonce;

    var currentUser = null; // { id, display_name, email }
    var searchTimer = null;

    function setBadge(active) {
      statusBadge.textContent = active ? 'Active' : 'Inactive';
      statusBadge.style.color = active ? '#0a7a0a' : '#888';
    }

    function selectUser(userId) {
      ajaxGet('wca_get_login_notify', nonce, { user_id: userId }).then(function (res) {
        if (!res || !res.success) return;
        var d = res.data;
        currentUser = { id: d.user_id, display_name: d.display_name, email: d.email };

        userIdEl.value = d.user_id;
        nameEl.textContent = d.display_name;
        emailEl.textContent = d.email;
        setBadge(d.active);
        messageEl.value = d.message || '';
        editor.style.display = 'block';
        statusEl.textContent = '';

        resultsBox.hidden = true;
        resultsBox.innerHTML = '';
        searchInput.value = '';
      });
    }

    // -- Search ------------------------------------------------------------

    searchInput.addEventListener('input', function () {
      clearTimeout(searchTimer);
      var term = searchInput.value.trim();

      if (term.length < 2) {
        resultsBox.hidden = true;
        resultsBox.innerHTML = '';
        return;
      }

      searchTimer = setTimeout(function () {
        ajaxGet('wca_user_search', nonce, { search: term }).then(function (res) {
          if (!res || !res.success) return;
          var users = res.data.users || [];
          resultsBox.innerHTML = '';

          if (!users.length) {
            resultsBox.hidden = true;
            return;
          }

          users.forEach(function (u) {
            var item = document.createElement('div');
            item.className = 'wca-notify-search-result';
            item.textContent = u.display_name + ' (' + u.email + ')' + (u.has_notice ? ' - has notice' : '');
            item.addEventListener('click', function () { selectUser(u.id); });
            resultsBox.appendChild(item);
          });

          resultsBox.hidden = false;
        });
      }, 300);
    });

    document.addEventListener('click', function (e) {
      if (resultsBox && !resultsBox.hidden && !resultsBox.contains(e.target) && e.target !== searchInput) {
        resultsBox.hidden = true;
      }
    });

    // -- Save / Clear (editor panel) -----------------------------------------

    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        if (!userIdEl.value) return;
        saveBtn.disabled = true;
        statusEl.style.color = '#666';
        statusEl.textContent = 'Saving…';

        ajaxPost('wca_save_login_notify', nonce, {
          user_id: userIdEl.value,
          message: messageEl.value,
        }).then(function (res) {
          saveBtn.disabled = false;
          if (res && res.success) {
            statusEl.style.color = '#0a7a0a';
            statusEl.textContent = 'Saved.';
            setBadge(res.data.active);
            if (res.data.active && currentUser) {
              upsertActiveRow(currentUser.id, currentUser.display_name, currentUser.email, res.data.message);
            } else {
              removeActiveRow(userIdEl.value);
            }
          } else {
            statusEl.style.color = '#c00';
            statusEl.textContent = (res && res.data && res.data.message) || 'Failed to save.';
          }
        }).catch(function () {
          saveBtn.disabled = false;
          statusEl.style.color = '#c00';
          statusEl.textContent = 'Network error.';
        });
      });
    }

    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        if (!userIdEl.value) return;
        if (!window.confirm('Clear this notification?')) return;

        clearBtn.disabled = true;
        ajaxPost('wca_save_login_notify', nonce, { user_id: userIdEl.value, message: '', clear: '1' }).then(function (res) {
          clearBtn.disabled = false;
          if (res && res.success) {
            messageEl.value = '';
            statusEl.style.color = '#0a7a0a';
            statusEl.textContent = 'Cleared.';
            setBadge(false);
            removeActiveRow(userIdEl.value);
          }
        }).catch(function () {
          clearBtn.disabled = false;
        });
      });
    }

    // -- Active Notifications table: Edit / Clear row actions (delegated) ---

    document.addEventListener('click', function (e) {
      var editBtn = e.target.closest('.wca-notify-row-edit');
      if (editBtn) {
        selectUser(editBtn.getAttribute('data-user-id'));
        if (editor) editor.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }

      var rowClearBtn = e.target.closest('.wca-notify-row-clear');
      if (rowClearBtn) {
        if (!window.confirm('Clear this notification?')) return;
        var userId = rowClearBtn.getAttribute('data-user-id');

        ajaxPost('wca_save_login_notify', nonce, { user_id: userId, message: '', clear: '1' }).then(function (res) {
          if (res && res.success) {
            removeActiveRow(userId);
            if (userIdEl.value === userId) {
              setBadge(false);
              messageEl.value = '';
            }
          }
        });
      }
    });

    // -- Active Notifications table: local DOM update helpers ---------------

    function excerpt(text) {
      var stripped = String(text || '').replace(/<[^>]*>/g, '');
      return stripped.length > 80 ? stripped.slice(0, 80) + '…' : stripped;
    }

    function activeTableBody() {
      var table = document.getElementById('wca-notify-active-table');
      return table ? table.querySelector('tbody') : null;
    }

    function upsertActiveRow(id, name, email, message) {
      var tbody = activeTableBody();
      if (!tbody) return;

      var emptyRow = tbody.querySelector('tr[data-empty]');
      if (emptyRow) emptyRow.remove();

      var row = tbody.querySelector('tr[data-user-id="' + id + '"]');
      if (!row) {
        row = document.createElement('tr');
        row.setAttribute('data-user-id', id);
        row.innerHTML = '<td></td><td></td><td>'
          + '<button type="button" class="button button-small wca-notify-row-edit" data-user-id="' + id + '">Edit</button> '
          + '<button type="button" class="button button-small wca-notify-row-clear" data-user-id="' + id + '">Clear</button>'
          + '</td>';
        tbody.prepend(row);
      }

      row.children[0].innerHTML = escapeHtml(name) + '<br><small style="color:#888;">' + escapeHtml(email) + '</small>';
      row.children[1].textContent = excerpt(message);
    }

    function removeActiveRow(id) {
      var tbody = activeTableBody();
      if (!tbody) return;

      var row = tbody.querySelector('tr[data-user-id="' + id + '"]');
      if (row) row.remove();

      if (!tbody.querySelector('tr')) {
        tbody.innerHTML = '<tr data-empty><td colspan="3">No active notifications.</td></tr>';
      }
    }
  }
})();
