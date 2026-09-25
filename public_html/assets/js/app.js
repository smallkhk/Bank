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

// Cards: reveal details after password re-auth; hide automatically after 30 seconds.
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-reveal-form]');
    if (form) {
      var target = document.querySelector('[data-reveal-target]');
      var num = target.querySelector('[data-cv-number]'), cvvWrap = target.querySelector('[data-cv-cvv-wrap]'), cvv = target.querySelector('[data-cv-cvv]');
      var masked = num.textContent, hideTimer;
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = form.querySelector('button'); btn.disabled = true;
        fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-CSRF-TOKEN': form.querySelector('input[name=_csrf]').value } })
          .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.error || 'Failed'); return d; }); })
          .then(function (d) {
            num.textContent = d.number; cvv.textContent = d.cvv; cvvWrap.hidden = false; form.reset();
            clearTimeout(hideTimer);
            hideTimer = setTimeout(function () { num.textContent = masked; cvv.textContent = ''; cvvWrap.hidden = true; }, 30000);
          })
          .catch(function (err) { alert(err.message); })
          .then(function () { btn.disabled = false; });
      });
    }
    // Card request: linked account only applies to debit/prepaid products
    var req = document.querySelector('[data-card-request]');
    if (req) {
      var sel = req.querySelector('[data-product]'), field = req.querySelector('[data-account-field]');
      var sync = function () { field.hidden = sel.options[sel.selectedIndex].getAttribute('data-type') === 'credit'; };
      sel.addEventListener('change', sync); sync();
    }
  });
})();

// Crypto trade form: Buy/Sell toggle, server-priced review step, confirm at the reviewed price.
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-trade-form]');
    if (!form) return;
    var side = form.querySelector('input[name=side]'), expected = form.querySelector('input[name=expected_price]');
    var box = form.querySelector('[data-quote-box]'), review = form.querySelector('[data-review]'), confirmBtn = form.querySelector('[data-confirm-btn]');
    var csrf = form.querySelector('input[name=_csrf]').value;
    function reset() { box.hidden = true; confirmBtn.hidden = true; expected.value = ''; }
    function setSide(s) {
      side.value = s;
      document.querySelectorAll('[data-seg] a').forEach(function (a) { a.classList.toggle('active', a.getAttribute('data-side') === s); });
      form.querySelectorAll('[data-when]').forEach(function (el) { el.hidden = el.getAttribute('data-when') !== s; });
      confirmBtn.textContent = s === 'buy' ? 'Confirm buy' : 'Confirm sell';
      reset();
    }
    document.querySelectorAll('[data-seg] a').forEach(function (a) {
      a.addEventListener('click', function (e) { e.preventDefault(); setSide(a.getAttribute('data-side')); });
    });
    form.addEventListener('input', reset);
    review.addEventListener('click', function () {
      review.disabled = true;
      fetch(form.getAttribute('data-quote'), { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } })
        .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.error || 'Could not price this order'); return d; }); })
        .then(function (q) {
          box.textContent = '';
          var rows = [['Price', q.price_fmt + ' per unit'], [q.side === 'buy' ? 'You receive' : 'You sell', q.quantity_fmt],
                      ['Value', q.gross_fmt], ['Fee', q.fee_fmt], [q.side === 'buy' ? 'Total to pay' : 'You receive', q.net_fmt]];
          rows.forEach(function (r) { var dt = document.createElement('dt'); dt.textContent = r[0]; var dd = document.createElement('dd'); dd.textContent = r[1]; box.appendChild(dt); box.appendChild(dd); });
          box.hidden = false;
          if (q.below_min) { var p = document.createElement('p'); p.className = 'neg small span-all'; p.textContent = 'Below the minimum trade size.'; box.appendChild(p); return; }
          expected.value = q.price; confirmBtn.hidden = false;
        })
        .catch(function (err) { alert(err.message); })
        .then(function () { review.disabled = false; });
    });
    setSide('buy');
  });
})();
