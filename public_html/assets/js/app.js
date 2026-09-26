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
      fetch(box.getAttribute('data-poll') + '?after=' + last, { headers: { Accept: 'application/json', 'X-Background': '1' }, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
        .then(function (d) {
          // Conversation list unread counts and support presence (customer chat app)
          if (d.unread) {
            document.querySelectorAll('[data-conv]').forEach(function (a) {
              var n = d.unread[a.getAttribute('data-conv')] || 0, b = a.querySelector('[data-conv-unread]');
              if (b && !a.classList.contains('active')) { b.textContent = n; b.hidden = n === 0; }
            });
          }
          if (typeof d.online === 'boolean') {
            var pr = document.querySelector('[data-presence]');
            if (pr) { pr.classList.toggle('on', d.online); pr.querySelector('[data-presence-text]').textContent = d.online ? 'Support is online' : 'We reply as soon as we can'; }
          }
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

// Theme, privacy mode, count-up figures and self-dismissing confirmations.
(function () {
  'use strict';
  var root = document.documentElement;
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function store(k, v) { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) {} }

  document.addEventListener('DOMContentLoaded', function () {
    // Light / dark toggle: flips whatever is currently showing and remembers the choice.
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var dark = root.getAttribute('data-theme') === 'dark' ||
          (!root.getAttribute('data-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches);
        var next = dark ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        store('theme', next);
      });
    });

    // Privacy mode blurs balances (useful in public). Remembered on this device only.
    var privacyOn = root.classList.contains('privacy-pending');
    function applyPrivacy() {
      document.body.classList.toggle('privacy', privacyOn);
      root.classList.remove('privacy-pending');
      document.querySelectorAll('[data-privacy-toggle]').forEach(function (b) {
        b.setAttribute('aria-pressed', privacyOn ? 'true' : 'false');
        b.title = privacyOn ? 'Show balances' : 'Hide balances';
      });
    }
    applyPrivacy();
    document.querySelectorAll('[data-privacy-toggle]').forEach(function (b) {
      b.addEventListener('click', function () { privacyOn = !privacyOn; store('privacy', privacyOn ? '1' : null); applyPrivacy(); });
    });

    // Count-up for headline amounts. The final text is always the server's exact figure.
    if (!reduceMotion) {
      var symbol = document.body.getAttribute('data-currency-symbol') || '';
      document.querySelectorAll('[data-countup]').forEach(function (el) {
        var target = parseInt(el.getAttribute('data-countup'), 10), finalText = el.textContent;
        if (!isFinite(target) || target <= 0 || finalText.indexOf(symbol) !== 0) return;
        var start = null, dur = 900;
        function frame(ts) {
          if (!start) start = ts;
          var p = Math.min(1, (ts - start) / dur), eased = 1 - Math.pow(1 - p, 3);
          var v = Math.round(target * eased) / 100;
          el.textContent = p < 1 ? symbol + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : finalText;
          if (p < 1) requestAnimationFrame(frame);
        }
        requestAnimationFrame(frame);
      });
    }

    // Success confirmations fade away after a few seconds; errors stay until the user acts.
    document.querySelectorAll('.alert[data-autohide]').forEach(function (el) {
      setTimeout(function () {
        el.classList.add('is-leaving');
        setTimeout(function () { el.remove(); }, 400);
      }, 6000);
    });
  });
})();

// Live attention badges: sidebar counts update on their own (no click or refresh needed).
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var url = document.body.getAttribute('data-badges-url');
    if (!url) return;
    var baseTitle = document.title.replace(/^\(\d+\+?\)\s*/, ''), timer = null;
    function paint(counts) {
      var total = 0;
      Object.keys(counts).forEach(function (k) {
        var n = counts[k] || 0;
        if (k !== 'notifications') total += n;
        document.querySelectorAll('[data-badge="' + k + '"]').forEach(function (el) {
          var old = parseInt(el.textContent, 10) || 0, cap = el.classList.contains('dot') ? 9 : 99;
          el.textContent = n > cap ? cap + '+' : n;
          el.hidden = n === 0;
          if (n > old && !el.hidden) { el.classList.remove('bump'); void el.offsetWidth; el.classList.add('bump'); }
        });
      });
      total += counts.notifications || 0;
      document.title = (total ? '(' + (total > 99 ? '99+' : total) + ') ' : '') + baseTitle;
    }
    function poll() {
      fetch(url, { headers: { Accept: 'application/json', 'X-Background': '1' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) { if (d && d.counts) paint(d.counts); })
        .catch(function () {})
        .then(schedule);
    }
    function schedule() { clearTimeout(timer); timer = setTimeout(poll, document.hidden ? 60000 : 12000); }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
    poll();
  });
})();

// Loading screen ("vault dial") for full page loads: form submits and in-app links.
(function () {
  'use strict';
  var overlay = null, msgTimer = null, fallback = null;
  var messages = ['Securing your session', 'Checking details', 'Almost there'];
  function build() {
    if (overlay) return overlay;
    var initial = (document.querySelector('.brand-mark') || { textContent: '' }).textContent.trim().charAt(0) || '';
    var ticks = '';
    for (var i = 0; i < 36; i++) {
      var long = i % 3 === 0;
      ticks += '<line x1="60" y1="' + (long ? 6 : 8) + '" x2="60" y2="' + (long ? 14 : 12) + '" transform="rotate(' + (i * 10) + ' 60 60)"/>';
    }
    overlay = document.createElement('div');
    overlay.className = 'vault-loader';
    overlay.setAttribute('role', 'status');
    overlay.setAttribute('aria-live', 'polite');
    overlay.hidden = true;
    overlay.innerHTML =
      '<div class="vault">' +
        '<svg class="vault-ticks" viewBox="0 0 120 120" aria-hidden="true"><g>' + ticks + '</g></svg>' +
        '<svg class="vault-arc" viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="40" pathLength="100"/></svg>' +
        '<svg class="vault-arc2" viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="31" pathLength="100"/></svg>' +
        '<span class="vault-core"></span>' +
      '</div>' +
      '<p class="vault-msg"></p>';
    overlay.querySelector('.vault-core').textContent = initial;
    document.body.appendChild(overlay);
    return overlay;
  }
  function show() {
    var el = build(), i = 0, msg = el.querySelector('.vault-msg');
    msg.textContent = messages[0];
    el.hidden = false;
    el.classList.add('is-on'); // visible immediately, no fade-in wait
    clearInterval(msgTimer);
    msgTimer = setInterval(function () { i = Math.min(i + 1, messages.length - 1); msg.textContent = messages[i]; }, 1400);
    clearTimeout(fallback);
    fallback = setTimeout(hide, 15000); // never trap the user if the page doesn't change (e.g. a download)
  }
  function hide() {
    clearInterval(msgTimer); clearTimeout(fallback);
    if (overlay) { overlay.classList.remove('is-on'); overlay.hidden = true; }
  }
  window.addEventListener('pageshow', hide); // back/forward cache
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.hasAttribute('data-no-loader') || form.target === '_blank' || /\/statement\b|export=csv/.test(form.getAttribute('action') || '')) return;
    // Run after other handlers: AJAX forms call preventDefault and must not show the loader.
    setTimeout(function () { if (!e.defaultPrevented) show(); }, 0);
  });
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('data-no-loader')) return;
    if (/^(mailto|tel|sms|javascript):/i.test(href) || /\/(statement|attachments)\b/.test(href) || /export=csv/.test(href)) return;
    if (a.origin && a.origin !== location.origin) return;
    if (a.pathname === location.pathname && a.search === location.search && a.hash) return;
    // Hold navigation briefly so the dial is actually seen on fast pages.
    e.preventDefault();
    show();
    setTimeout(function () { location.href = a.href; }, 550);
  });
})();
