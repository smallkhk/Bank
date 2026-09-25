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

// Chat: AJAX polling (works on any shared host; no WebSocket server required).
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var box = document.querySelector('[data-chat]');
    if (!box) return;
    var log = box.querySelector('[data-log]');
    var form = box.querySelector('[data-chat-form]');
    var me = box.getAttribute('data-me');
    var last = 0, busy = false, timer = null;
    var csrf = form.querySelector('input[name="_csrf"]').value;
    var attBase = box.getAttribute('data-att');

    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }

    function render(m) {
      var empty = log.querySelector('[data-empty]'); if (empty) empty.remove();
      var row = el('div', 'bubble ' + (m.sender === me ? 'mine' : 'theirs') + (m.internal ? ' internal' : ''));
      var meta = el('div', 'bubble-meta', m.name + ' · ' + m.time + (m.internal ? ' · internal note' : ''));
      var body = el('div', 'bubble-body', m.body); // textContent — no HTML injection
      row.appendChild(meta); row.appendChild(body);
      if (m.attachment) {
        var a = el('a', 'att', '📎 ' + m.attachment.name);
        a.href = attBase + '/' + m.attachment.id;
        a.target = '_blank'; a.rel = 'noopener';
        row.appendChild(a);
      }
      log.appendChild(row);
    }

    function poll() {
      if (busy) return;
      busy = true;
      fetch(box.getAttribute('data-poll') + '?after=' + last, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
        .then(function (d) {
          var atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 60;
          (d.messages || []).forEach(function (m) { if (m.id > last) { render(m); last = m.id; } });
          if (atBottom || last && d.messages && d.messages.length) log.scrollTop = log.scrollHeight;
        })
        .catch(function () {})
        .then(function () { busy = false; schedule(); });
    }
    function schedule() { clearTimeout(timer); timer = setTimeout(poll, document.hidden ? 15000 : 4000); }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(form);
      var text = (fd.get('message') || '').trim();
      var file = form.querySelector('input[type=file]');
      if (!text && !(file && file.files.length)) return;
      var btn = form.querySelector('button'); btn.disabled = true;
      fetch(form.action, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.error || 'Failed'); return d; }); })
        .then(function () { form.reset(); poll(); })
        .catch(function (err) { alert(err.message || 'Message could not be sent.'); })
        .then(function () { btn.disabled = false; form.querySelector('textarea').focus(); });
    });
    form.querySelector('textarea').addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
    });
    poll();
  });
})();
