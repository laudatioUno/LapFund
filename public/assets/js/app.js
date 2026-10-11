// LapFund – small progressive enhancements. Everything works without JavaScript.
(function () {
  'use strict';

  // Copy buttons: data-copy-target="<element id>"
  document.querySelectorAll('[data-copy-target]').forEach(function (button) {
    button.addEventListener('click', function () {
      var target = document.getElementById(button.getAttribute('data-copy-target'));
      if (!target) { return; }
      var text = 'value' in target ? target.value : target.innerText;
      var original = button.textContent;
      var done = function () {
        button.textContent = button.getAttribute('data-copied') || original;
        setTimeout(function () { button.textContent = original; }, 2000);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text.trim()).then(done);
      } else if (typeof target.select === 'function') {
        target.select();
        document.execCommand('copy');
        done();
      }
    });
  });

  // Confirm dialogs: <form data-confirm="Question?">
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      var question = form.getAttribute('data-confirm');
      if (question && !window.confirm(question)) { event.preventDefault(); }
    });
  });

  // Print button
  document.querySelectorAll('[data-print]').forEach(function (button) {
    button.addEventListener('click', function () { window.print(); });
  });

  // Live color preview in the settings
  var color = document.getElementById('f-primary_color');
  var sample = document.querySelector('[data-color-sample]');
  if (color && sample) {
    var update = function () {
      var hex = color.value.replace('#', '');
      var rgb = [0, 2, 4].map(function (i) {
        var c = parseInt(hex.substr(i, 2), 16) / 255;
        return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
      });
      var l = 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
      sample.style.setProperty('background', color.value);
      sample.style.setProperty('color', (1.05 / (l + 0.05)) >= ((l + 0.05) / 0.0632) ? '#FFFFFF' : '#14212E');
    };
    color.addEventListener('input', update);
    update();
  }

  // Mail progress (fallback without cron): every few seconds one send step, then update the numbers.
  var tick = document.querySelector('form[data-mail-tick]');
  if (tick && window.fetch && window.FormData) {
    var counts = document.querySelector('[data-mail-counts]');
    var status = document.querySelector('[data-mail-status]');
    var rounds = 0;
    var step = function () {
      rounds++;
      fetch(tick.action, { method: 'POST', body: new FormData(tick), credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data) { return; }
          var c = data.counts;
          var set = function (key, value) {
            var el = counts && counts.querySelector('[data-count="' + key + '"]');
            if (el) { el.textContent = value; }
          };
          set('pending', c.queued + c.sending);
          set('sent', c.sent);
          set('failed', c.failed);
          if (c.queued + c.sending === 0) {
            window.location.reload();
          } else if (rounds < 400) {
            setTimeout(step, 4000);
          } else if (status) {
            status.textContent = status.getAttribute('data-stopped') || '';
          }
        })
        .catch(function () { if (rounds < 400) { setTimeout(step, 10000); } });
    };
    setTimeout(step, 1500);
  }

  // Pledge form: quick amounts for the chosen type, hint per type
  var quick = document.querySelector('[data-quick]');
  var amount = document.getElementById('f-amount');
  if (quick && amount) {
    var types = document.querySelectorAll('[data-pledge-type]');
    var current = function () {
      var t = 'flat';
      types.forEach(function (r) { if (r.checked) { t = r.value; } });
      return t;
    };
    var sync = function () {
      var t = current();
      quick.querySelectorAll('[data-quick-type]').forEach(function (b) { b.hidden = b.getAttribute('data-quick-type') !== t; });
      document.querySelectorAll('[data-amount-hint]').forEach(function (h) { h.hidden = h.getAttribute('data-amount-hint') !== t; });
    };
    quick.hidden = false;
    types.forEach(function (r) { r.addEventListener('change', sync); });
    quick.addEventListener('click', function (e) {
      var b = e.target.closest('[data-quick-value]');
      if (b) { amount.value = b.getAttribute('data-quick-value'); amount.focus(); }
    });
    sync();
  }
})();
