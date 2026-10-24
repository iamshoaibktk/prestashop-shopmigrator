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
  var $ = function (id) { return document.getElementById(id); };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
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
    document.querySelectorAll('.sm-pane').forEach(function (p) { p.hidden = p.dataset.pane !== String(step); });
    document.querySelectorAll('.sm-steps li').forEach(function (li) {
      var n = +li.dataset.step;
      li.classList.toggle('on', n === step);
      li.classList.toggle('ok', n < step);
    });
  }

  document.querySelectorAll('[data-goto]').forEach(function (b) {
    b.addEventListener('click', function () { show(+b.dataset.goto); });
  });

  /* ---------- step 1: connect ---------- */
  $('sm-test').addEventListener('click', function () {
    var msg = $('sm-test-msg');
    msg.innerHTML = '<p class="help-block">' + esc(t.tTesting) + '</p>';
    $('sm-next').disabled = true;
    post('test', {sm_url: $('sm-url').value, sm_key: $('sm-key').value}).then(function (r) {
      if (!r.ok) {
        msg.innerHTML = '<div class="alert alert-danger">' + esc(r.error) + '</div>';
        $('sm-info').hidden = true;
        return;
      }
      msg.innerHTML = '';
      counts = r.counts || {};
      var i = r.info;
      var cell = function (n, l) { return '<div><div class="sm-num">' + esc(n) + '</div><div class="sm-lab">' + esc(l) + '</div></div>'; };
      $('sm-info').innerHTML = '<strong>' + esc(i.shop_name) + '</strong> · PrestaShop ' + esc(i.ps_version) + ' · PHP ' + esc(i.php_version)
        + '<div class="sm-grid">'
        + cell(counts.product || 0, t.tGProducts) + cell(counts.product_attribute || 0, t.tGCombinations) + cell(counts.category || 0, t.tGCategories)
        + cell(counts.image || 0, t.tGImages) + cell(counts.customer || 0, t.tGCustomers) + cell(counts.orders || 0, t.tGOrders)
        + '</div>'
        + (i.missingLanguages.length ? '<div class="alert alert-warning" style="margin:12px 0 0">' + esc(t.tNolang) + ' ' + esc(i.missingLanguages.join(', ')) + '</div>' : '');
      $('sm-info').hidden = false;
      $('sm-next').disabled = false;
      buildTypes();
    }).catch(function (e) { msg.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>'; });
  });
  $('sm-next').addEventListener('click', function () { show(2); });
  document.querySelectorAll('input[name="sm-mode"]').forEach(function (r) {
    r.addEventListener('change', function () { $('sm-fresh-note').hidden = r.value !== 'fresh' || !r.checked; });
  });

  /* ---------- step 2: choose ---------- */
  var typeCount = {taxes: 'tax', manufacturers: 'manufacturer', suppliers: 'supplier', categories: 'category', products: 'product', carriers: 'carrier', customers: 'customer', orders: 'orders', cart_rules: 'cart_rule', contacts: 'contact', employees: 'employee', price_rules: 'specific_price_rule', cms: 'cms'};
  function buildTypes() {
    $('sm-typelist').innerHTML = Object.keys(types).filter(function (k) { return k !== 'images'; }).map(function (k) {
      var c = counts[typeCount[k]];
      return '<label><input type="checkbox" value="' + esc(k) + '" checked> ' + esc(types[k]) + (c != null ? '<span class="sm-count">' + esc(c) + '</span>' : '') + '</label>';
    }).join('');
    // Selecting products selects what products need (categories, taxes…).
    $('sm-typelist').addEventListener('change', function (e) {
      if (e.target.checked && needs[e.target.value]) {
        needs[e.target.value].forEach(function (n) {
          var cb = $('sm-typelist').querySelector('input[value="' + n + '"]');
          if (cb) { cb.checked = true; }
        });
      }
    });
  }

  $('sm-start').addEventListener('click', function () {
    var chosen = [].map.call($('sm-typelist').querySelectorAll('input:checked'), function (c) { return c.value; });
    if (!chosen.length) {
      return;
    }
    $('sm-start').disabled = true;
    var mode = (document.querySelector('input[name="sm-mode"]:checked') || {}).value || 'fresh';
    if (mode === 'fresh' && !window.confirm(t.tConfirm)) {
      return;
    }
    post('start', {sm_types: chosen, sm_batch: $('sm-batch').value, sm_mode: mode, sm_thumbs: $('sm-thumbs').checked ? '1' : '0'}).then(function (r) {
      $('sm-start').disabled = false;
      if (!r.ok) {
        window.alert(r.error);
        return;
      }
      show(3);
      render(r);
      loop();
    });
  });

  /* ---------- step 3: migrate ---------- */
  function render(p) {
    var label = {running: t.tRunning, paused: t.tPaused, done: t.tDone, error: t.tError}[p.status] || '';
    $('sm-status').textContent = label;
    $('sm-bars').innerHTML = Object.keys(p.groups || {}).map(function (g) {
      var x = p.groups[g];
      var pct = x.total ? Math.min(100, Math.round((x.done / x.total) * 100)) : (x.state === 'done' ? 100 : 0);
      return '<div class="sm-bar' + (x.state === 'done' ? ' done' : '') + '"><div class="sm-bar-top"><span>' + esc(types[g] || g) + '</span><span>'
        + esc(x.done) + ' / ' + esc(x.total || '…') + '</span></div><div class="sm-track"><div class="sm-fill" style="width:' + pct + '%"></div></div></div>';
    }).join('');
    $('sm-current').textContent = p.status === 'running' && p.current ? t.tWorking + ' ' + p.current + ' · ' + (p.step + 1) + '/' + p.steps : '';
    if (p.status === 'error') {
      $('sm-current').innerHTML = '<span class="text-danger">' + esc(p.error) + '</span>';
    }
    $('sm-pause').hidden = p.status !== 'running';
    $('sm-resume').hidden = p.status !== 'paused' && p.status !== 'error';
    if (p.status === 'done') {
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
    $('sm-summary').innerHTML = '<p>' + esc(p.source ? 'PrestaShop ' + p.source.ps_version + ' → ' : '') + esc(Math.floor(secs / 60) + ' min ' + (secs % 60) + ' s') + '</p>'
      + Object.keys(p.groups).map(function (g) {
        return '<div class="sm-summary-row"><span>' + esc(types[g] || g) + '</span><strong>' + esc(p.groups[g].records != null ? p.groups[g].records : p.groups[g].done) + '</strong></div>';
      }).join('');
    $('sm-warn-count').textContent = p.warningCount;
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
