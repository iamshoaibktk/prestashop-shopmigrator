/**
 * Shop Migrator wizard: connect → choose → migrate (batches) → report.
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
(function () {
  'use strict';

  var app = document.getElementById('sm-app');
  if (!app) {
    return;
  }
  var t = app.dataset;
  var types = JSON.parse(t.types);
  var needs = JSON.parse(t.needs);
  var counts = {};
  var running = false;
  var samples = []; // [time, rows done] for the speed and time-remaining figures
  var $ = function (id) { return document.getElementById(id); };
  var icons = {
    taxes: 'account_balance', manufacturers: 'local_offer', suppliers: 'store', categories: 'category',
    attributes: 'tune', products: 'shopping_basket', carriers: 'local_shipping', customers: 'people',
    cart_rules: 'confirmation_number', contacts: 'contact_mail', employees: 'supervisor_account',
    orders: 'receipt_long', price_rules: 'sell', cms: 'description', images: 'image'
  };
  var typeCount = {taxes: 'tax', manufacturers: 'manufacturer', suppliers: 'supplier', categories: 'category', products: 'product', carriers: 'carrier', customers: 'customer', orders: 'orders', cart_rules: 'cart_rule', contacts: 'contact', employees: 'employee', price_rules: 'specific_price_rule', cms: 'cms'};

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
  }
  function num(n) {
    return (+n || 0).toLocaleString();
  }
  function icon(name) {
    return '<i class="material-icons">' + name + '</i>';
  }
  function duration(s) {
    s = Math.max(0, Math.round(s));
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), r = s % 60;
    return h ? h + 'h ' + (m < 10 ? '0' : '') + m + 'm' : (m ? m + 'm ' + (r < 10 ? '0' : '') + r + 's' : r + 's');
  }
  function radio(name) {
    var r = app.querySelector('input[name="' + name + '"]:checked');
    return r ? r.value : null;
  }
  function loading(btn, on) {
    btn.classList.toggle('is-loading', on);
    btn.disabled = on;
  }

  function post(action, data) {
    var body = new FormData();
    body.append('sm_action', action);
    Object.keys(data || {}).forEach(function (k) {
      [].concat(data[k]).forEach(function (v) { body.append(Array.isArray(data[k]) ? k + '[]' : k, v); });
    });
    return fetch(t.ajax, {method: 'POST', body: body, credentials: 'same-origin'}).then(function (r) { return r.json(); });
  }

  function show(step) {
    app.querySelectorAll('.sm-pane').forEach(function (p) { p.hidden = p.dataset.pane !== String(step); });
    app.querySelectorAll('.sm-stepper li').forEach(function (li) {
      var n = +li.dataset.step;
      li.classList.toggle('on', n === step);
      li.classList.toggle('ok', n < step);
      if (n === step) { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); }
    });
  }

  app.querySelectorAll('[data-goto]').forEach(function (b) {
    b.addEventListener('click', function () { show(+b.dataset.goto); });
  });

  /* ---------- step 1: connect ---------- */
  $('sm-test').addEventListener('click', function () {
    var btn = this;
    var msg = $('sm-test-msg');
    msg.innerHTML = '';
    $('sm-next').disabled = true;
    loading(btn, true);
    post('test', {sm_url: $('sm-url').value, sm_key: $('sm-key').value}).then(function (r) {
      loading(btn, false);
      if (!r.ok) {
        msg.innerHTML = '<div class="sm-note sm-note-error">' + icon('error_outline') + '<span>' + esc(r.error) + '</span></div>';
        $('sm-info').hidden = true;
        return;
      }
      counts = r.counts || {};
      var i = r.info;
      var cell = function (n, l) { return '<div><div class="sm-num">' + num(n) + '</div><div class="sm-lab">' + esc(l) + '</div></div>'; };
      $('sm-info').innerHTML = '<div class="sm-source-head">' + icon('check_circle') + '<span>' + esc(i.shop_name) + '</span>'
        + '<span class="sm-tag">PrestaShop ' + esc(i.ps_version) + ' · PHP ' + esc(i.php_version) + '</span></div>'
        + '<div class="sm-grid">'
        + cell(counts.product, t.tGProducts) + cell(counts.product_attribute, t.tGCombinations) + cell(counts.category, t.tGCategories)
        + cell(counts.image, t.tGImages) + cell(counts.customer, t.tGCustomers) + cell(counts.orders, t.tGOrders)
        + '</div>'
        + (i.missingLanguages.length ? '<div class="sm-note sm-note-info" style="margin:0;border-radius:0">' + icon('translate') + '<span>' + esc(t.tNolang) + ' ' + esc(i.missingLanguages.join(', ')) + '</span></div>' : '');
      $('sm-info').hidden = false;
      $('sm-source-line').textContent = i.shop_name + ' · PrestaShop ' + i.ps_version;
      $('sm-next').disabled = false;
      buildTypes();
    }).catch(function (e) {
      loading(btn, false);
      msg.innerHTML = '<div class="sm-note sm-note-error">' + icon('error_outline') + '<span>' + esc(e.message) + '</span></div>';
    });
  });
  $('sm-next').addEventListener('click', function () { show(2); });

  /* ---------- step 2: choose ---------- */
  function buildTypes() {
    $('sm-typelist').innerHTML = Object.keys(types).filter(function (k) { return k !== 'images'; }).map(function (k) {
      var c = counts[typeCount[k]];
      var label = String(types[k]);
      var paren = label.indexOf(' (');
      var title = paren > 0 ? label.slice(0, paren) : label;
      var sub = paren > 0 ? label.slice(paren + 2, -1) : '';
      return '<label class="sm-tile"><input type="checkbox" value="' + esc(k) + '" checked>'
        + '<span class="sm-tile-icon">' + icon(icons[k] || 'inventory') + '</span>'
        + '<span class="sm-tile-text">' + esc(title) + (c != null ? ' <span class="sm-tag">' + num(c) + '</span>' : '') + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</span>'
        + '<span class="sm-check"></span></label>';
    }).join('');
  }
  // Selecting orders selects what orders need (customers, products…).
  $('sm-typelist').addEventListener('change', function (e) {
    if (e.target.checked && needs[e.target.value]) {
      needs[e.target.value].forEach(function (n) {
        var cb = $('sm-typelist').querySelector('input[value="' + n + '"]');
        if (cb) { cb.checked = true; }
      });
    }
  });
  app.querySelectorAll('input[name="sm-mode"]').forEach(function (r) {
    r.addEventListener('change', function () { $('sm-fresh-note').hidden = radio('sm-mode') !== 'fresh'; });
  });

  $('sm-start').addEventListener('click', function () {
    var btn = this;
    var chosen = [].map.call($('sm-typelist').querySelectorAll('input:checked'), function (c) { return c.value; });
    var mode = radio('sm-mode') || 'fresh';
    if (!chosen.length || (mode === 'fresh' && !window.confirm(t.tConfirm))) {
      return;
    }
    loading(btn, true);
    post('start', {
      sm_types: chosen, sm_batch: radio('sm-batch') || 100, sm_mode: mode,
      sm_thumbs: $('sm-thumbs').checked ? '1' : '0', sm_addloc: $('sm-addloc').checked ? '1' : '0'
    }).then(function (r) {
      loading(btn, false);
      if (!r.ok) {
        window.alert(r.error);
        return;
      }
      samples = [];
      show(3);
      render(r);
      loop();
    }).catch(function (e) { loading(btn, false); window.alert(e.message); });
  });

  /* ---------- step 3: migrate ---------- */
  function totals(p) {
    var done = 0, total = 0;
    Object.keys(p.groups || {}).forEach(function (g) {
      var x = p.groups[g];
      total += Math.max(x.total || 0, x.done || 0);
      done += x.done || 0;
    });
    return {done: done, total: total};
  }

  function render(p) {
    var status = p.status || 'idle';
    var label = {running: t.tRunning, paused: t.tPaused, done: t.tDone, error: t.tError}[status] || '';
    $('sm-status').textContent = label;
    $('sm-status-pill').className = 'sm-status' + (status === 'running' ? '' : ' is-' + status);
    var hero = app.querySelector('.sm-hero');
    hero.classList.toggle('is-done', status === 'done');
    hero.classList.toggle('is-paused', status === 'paused' || status === 'error');
    app.classList.toggle('is-halted', status !== 'running');

    // Overall progress: rows of every table and every image file.
    var sum = totals(p);
    var pct = status === 'done' ? 100 : (sum.total ? Math.min(99, Math.floor((sum.done / sum.total) * 100)) : 0);
    $('sm-pct').textContent = pct;
    $('sm-meter-fill').style.width = pct + '%';
    $('sm-meter').setAttribute('aria-valuenow', pct);

    var now = Date.now() / 1000;
    if (status === 'running') {
      samples.push([now, sum.done]);
      samples = samples.filter(function (s) { return now - s[0] < 60; }); // the last minute
    }
    var span = samples.length > 1 ? samples[samples.length - 1][0] - samples[0][0] : 0;
    var rate = span > 0 ? (samples[samples.length - 1][1] - samples[0][1]) / span : 0;
    $('sm-elapsed').textContent = p.started ? duration((p.finished || now) - p.started) : '–';
    $('sm-speed').textContent = rate > 0 ? num(Math.round(rate)) + ' ' + t.tPersec : '–';
    $('sm-eta').textContent = status === 'done' ? duration(0) : (rate > 0 && sum.total > sum.done ? duration((sum.total - sum.done) / rate) : '–');

    $('sm-bars').innerHTML = Object.keys(p.groups || {}).map(function (g) {
      var x = p.groups[g];
      var state = x.state === 'done' ? 'done' : (x.state === 'active' ? 'active' : 'waiting');
      var total = Math.max(x.total || 0, x.done || 0);
      var w = state === 'done' ? 100 : (total ? Math.min(100, Math.round((x.done / total) * 100)) : 0);
      var stateIcon = state === 'done' ? 'check' : (state === 'active' ? 'autorenew' : (icons[g] || 'schedule'));
      var count = state === 'waiting' && !x.done ? t.tWaiting : num(x.done) + (total ? ' / ' + num(total) : '');
      return '<li class="sm-row is-' + state + '"><span class="sm-row-state">' + icon(stateIcon) + '</span>'
        + '<span class="sm-row-name">' + esc(String(types[g] || g).replace(/ \(.*\)$/, '')) + '</span><span class="sm-row-count">' + esc(count) + '</span>'
        + '<span class="sm-row-track"><span class="sm-row-fill" style="display:block;width:' + w + '%"></span></span></li>';
    }).join('');

    $('sm-current').textContent = status === 'running' && p.current ? t.tWorking + ' ' + p.current + ' · ' + (p.step + 1) + '/' + p.steps : '';
    if (status === 'error') {
      $('sm-current').innerHTML = '<span class="text-danger">' + esc(p.error) + '</span>';
    }
    $('sm-pause').hidden = status !== 'running';
    $('sm-resume').hidden = status !== 'paused' && status !== 'error';
    if (status === 'done') {
      report(p);
    }
  }

  function loop() {
    if (running) {
      return;
    }
    running = true;
    (function next() {
      post('tick').then(function (r) {
        if (!r.ok) {
          running = false;
          $('sm-current').innerHTML = '<span class="text-danger">' + esc(r.error) + '</span>';
          return;
        }
        render(r);
        if (r.status === 'running') {
          next();
        } else {
          running = false;
        }
      }).catch(function () {
        // Network hiccup: try again shortly; the server kept its place.
        setTimeout(next, 3000);
      });
    })();
  }

  $('sm-pause').addEventListener('click', function () { post('pause').then(render); });
  $('sm-resume').addEventListener('click', function () { post('resume').then(function (r) { render(r); loop(); }); });

  /* ---------- step 4: report ---------- */
  var last = null;
  function report(p) {
    last = p;
    show(4);
    var secs = p.finished && p.started ? p.finished - p.started : 0;
    $('sm-summary-line').textContent = (p.source ? (p.source.name ? p.source.name + ' · ' : '') + 'PrestaShop ' + p.source.ps_version + ' → PrestaShop 9 · ' : '') + duration(secs);
    $('sm-summary').innerHTML = Object.keys(p.groups).map(function (g) {
      var x = p.groups[g];
      return '<div class="sm-stat"><span class="sm-tile-icon">' + icon(icons[g] || 'check') + '</span><div>'
        + '<div class="sm-num">' + num(x.records != null ? x.records : x.done) + '</div><div class="sm-lab">' + esc(String(types[g] || g).replace(/ \(.*\)$/, '')) + '</div></div></div>';
    }).join('');
    $('sm-warn-count').textContent = p.warningCount;
    $('sm-warn-count').classList.toggle('is-zero', !p.warningCount);
    $('sm-warn-box').open = p.warningCount > 0 && p.warningCount < 20;
    $('sm-warnings').innerHTML = (p.warnings || []).map(function (w) { return '<div>' + esc(w.msg) + '</div>'; }).join('') || '<div>—</div>';
  }

  $('sm-download').addEventListener('click', function () {
    if (!last) {
      return;
    }
    var lines = ['Shop Migrator report', 'Source: PrestaShop ' + (last.source ? last.source.ps_version : '?'), ''];
    Object.keys(last.groups).forEach(function (g) { var x = last.groups[g]; lines.push((types[g] || g) + ': ' + (x.records != null ? x.records : x.done)); });
    lines.push('', 'Warnings (' + last.warningCount + '):');
    (last.warnings || []).forEach(function (w) { lines.push('- ' + w.msg); });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([lines.join('\n')], {type: 'text/plain'}));
    a.download = 'shop-migrator-report.txt';
    a.click();
  });

  $('sm-reset').addEventListener('click', function () {
    if (window.confirm(t.tReset)) {
      post('reset').then(function () { show(1); });
    }
  });

  /* ---------- reopen where we left off ---------- */
  var state = JSON.parse(t.state);
  if (state.status === 'running' || state.status === 'paused' || state.status === 'error') {
    show(3);
    render(state);
    if (state.status === 'running') {
      loop();
    }
  } else if (state.status === 'done') {
    render(state);
  } else {
    show(1);
  }
})();
