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

  // Sharing on phones (issue #13)
  var ua = navigator.userAgent || '';
  var isPhone = /iPhone|iPad|iPod|Android/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  // WhatsApp: on phones open the app's «send to» picker directly instead of going through wa.me
  if (isPhone) {
    document.querySelectorAll('a[data-app-href]').forEach(function (link) {
      link.setAttribute('href', link.getAttribute('data-app-href'));
      link.removeAttribute('target');
    });
  }
  // «Share …» opens the phone's share sheet (WhatsApp, Signal, Threema, SMS …) where supported
  document.querySelectorAll('[data-share-text]').forEach(function (button) {
    if (typeof navigator.share !== 'function') { return; }
    button.hidden = false;
    button.addEventListener('click', function () {
      navigator.share({ title: button.getAttribute('data-share-title') || undefined, text: button.getAttribute('data-share-text') })
        .catch(function () { /* cancelled by the user */ });
    });
  });

  // Save buttons stay inactive until something in the form changed: <form data-dirty-check>
  document.querySelectorAll('form[data-dirty-check]').forEach(function (form) {
    var buttons = form.querySelectorAll('button[type="submit"]');
    var hint = form.getAttribute('data-dirty-check');
    var set = function (enabled) {
      buttons.forEach(function (b) {
        b.disabled = !enabled;
        if (hint) { b.title = enabled ? '' : hint; }
      });
    };
    set(false);
    var enable = function () { set(true); };
    form.addEventListener('input', enable);
    form.addEventListener('change', enable);
    // Back/forward cache: a restored page starts clean again
    window.addEventListener('pageshow', function (e) { if (e.persisted) { set(false); } });
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

  // Club settings › Kontakt halten: fields locked while the switch is off, live preview
  var contacts = document.querySelector('[data-contacts]');
  if (contacts) {
    var toggle = document.getElementById('f-contacts_enabled');
    var title = document.getElementById('f-contacts_title');
    var text = document.getElementById('f-contacts_text');
    var clubInput = document.getElementById('f-club_name');
    var previewBox = document.querySelector('.contacts-preview');
    var previewTitle = document.querySelector('[data-preview-title]');
    var previewText = document.querySelector('[data-preview-text]');
    var previewOff = document.querySelector('[data-preview-off]');
    var example = document.querySelector('[data-contacts-example]');
    var defaults = function (club) {
      return {
        title: contacts.getAttribute('data-default-title').split('{club}').join(club),
        text: contacts.getAttribute('data-default-text').split('{club}').join(club)
      };
    };
    var club = contacts.getAttribute('data-club');
    var render = function () {
      var now = defaults(clubInput && clubInput.value.trim() !== '' ? clubInput.value.trim() : club);
      previewTitle.textContent = title.value.trim() !== '' ? title.value.trim() : now.title;
      previewText.textContent = text.value.trim() !== '' ? text.value.trim() : now.text;
      if (example) { example.textContent = now.title; }
    };
    var lock = function () {
      var off = !toggle.checked;
      [title, text].forEach(function (field) {
        field.readOnly = off;
        if (off) { field.setAttribute('aria-disabled', 'true'); } else { field.removeAttribute('aria-disabled'); }
      });
      contacts.classList.toggle('is-off', off);
      previewBox.classList.toggle('is-off', off);
      previewOff.hidden = !off;
    };
    // A renamed club: texts still on the default follow the new name (as when saving)
    if (clubInput) {
      clubInput.addEventListener('input', function () {
        var before = defaults(club);
        var now = defaults(clubInput.value.trim() !== '' ? clubInput.value.trim() : club);
        if (title.value.trim() === before.title) { title.value = now.title; }
        if (text.value.trim() === before.text) { text.value = now.text; }
        club = clubInput.value.trim() !== '' ? clubInput.value.trim() : club;
        render();
      });
    }
    title.addEventListener('input', render);
    text.addEventListener('input', render);
    toggle.addEventListener('change', lock);
    lock();
    render();
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

  // Lap entry: Enter jumps to the next participant instead of sending the form
  var lapInputs = Array.prototype.slice.call(document.querySelectorAll('.lap-input'));
  lapInputs.forEach(function (input, i) {
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        var next = lapInputs[i + 1];
        if (next) { next.focus(); next.select(); }
      }
    });
  });
})();
