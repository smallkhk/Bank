// Small progressive enhancements. The app works fully without JavaScript.
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    // Mobile navigation
    var toggle = document.querySelector('[data-nav-toggle]');
    if (toggle) {
      toggle.addEventListener('click', function () {
        var open = document.body.classList.toggle('nav-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      document.addEventListener('click', function (e) {
        if (document.body.classList.contains('nav-open') && !e.target.closest('.sidebar') && !e.target.closest('[data-nav-toggle]')) {
          document.body.classList.remove('nav-open');
          toggle.setAttribute('aria-expanded', 'false');
        }
      });
    }

    // Confirmation prompts
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        var form = el.closest('form');
        if (form && !form.checkValidity()) return; // let the browser show validation first
        if (!window.confirm(el.getAttribute('data-confirm'))) e.preventDefault();
      });
    });

    // Prevent double submission of forms
    document.querySelectorAll('form[method="post"]').forEach(function (form) {
      form.addEventListener('submit', function () {
        setTimeout(function () {
          form.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        }, 0);
      });
    });

    // Print buttons
    document.querySelectorAll('[data-print]').forEach(function (b) {
      b.addEventListener('click', function () { window.print(); });
    });

    // Transfer fee preview
    var tf = document.querySelector('[data-transfer-form]');
    if (tf) {
      var amount = tf.querySelector('[data-amount]');
      var note = tf.querySelector('[data-fee-note]');
      var fixed = parseInt(tf.getAttribute('data-fee-fixed'), 10) || 0;
      var bps = parseInt(tf.getAttribute('data-fee-bps'), 10) || 0;
      var update = function () {
        var cents = Math.round(parseFloat((amount.value || '0').replace(/,/g, '')) * 100) || 0;
        var fee = fixed + Math.floor((cents * bps + 5000) / 10000);
        note.textContent = cents > 0 && fee > 0 ? 'Fee: ' + (fee / 100).toFixed(2) + ' · Total debited: ' + ((cents + fee) / 100).toFixed(2) : '';
      };
      amount.addEventListener('input', update);
      update();
    }
  });
})();
