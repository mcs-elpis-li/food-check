<?php
declare(strict_types=1);

/**
 * Log viewer for the Food Check backend.
 *
 * Open /log in a browser, paste the backend API key once, and the last 30
 * analysis entries are shown. The key is kept in sessionStorage only.
 */

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../lib.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Type: text/html; charset=utf-8');

$hasKey = (string) $config['app_api_key'] !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#d81b60">
<title>Food Check logs 食物質地檢查記錄</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #fff0f6; --card: #ffffff; --text: #2d1b26; --muted: #7a5567;
    --accent: #d81b60; --accent-dark: #a0004a;
    --pink-soft: #ffe4ef; --pink-border: #f2b8cf;
    --green: #0b7a4b; --green-bg: #e2f3e9;
    --orange: #b45309; --orange-bg: #fdf0dd;
    --red: #b91c1c; --red-bg: #fde8e8;
  }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang HK", "Microsoft JhengHei", sans-serif;
    background: linear-gradient(180deg, #ffe4ef 0, #fff0f6 260px);
    color: var(--text); font-size: 17px; line-height: 1.5; padding-bottom: 40px;
  }
  header {
    background: linear-gradient(135deg, #d81b60, #ad1457); color: #fff;
    padding: 16px; position: sticky; top: 0; z-index: 10;
  }
  header h1 { font-size: 22px; font-weight: 800; }
  header .sub { font-size: 15px; opacity: .95; }
  main { padding: 14px; max-width: 1000px; margin: 0 auto; }
  .card {
    background: var(--card); border: 1px solid var(--pink-border); border-radius: 16px;
    padding: 16px; margin-bottom: 14px; box-shadow: 0 3px 12px rgba(216,27,96,.10);
  }
  .label { font-size: 16px; font-weight: 800; color: var(--accent-dark); margin: 6px 0 8px; }
  input, button {
    font-family: inherit; font-size: 17px; padding: 12px 14px;
    border-radius: 12px; border: 2px solid var(--pink-border); min-height: 52px; width: 100%;
  }
  button {
    background: var(--accent); color: #fff; border: none; font-weight: 700; cursor: pointer; width: auto;
  }
  button:hover { background: var(--accent-dark); }
  .row { display: flex; gap: 10px; flex-wrap: wrap; }
  .row input { flex: 1; min-width: 220px; }
  .status { font-size: 16px; margin-top: 10px; padding: 10px 12px; border-radius: 12px; display: none; }
  .status.show { display: block; }
  .status.err { background: var(--red-bg); color: var(--red); }
  .status.ok { background: var(--green-bg); color: var(--green); }
  .entry { border: 1px solid var(--pink-border); border-radius: 14px; padding: 12px 14px; margin-bottom: 12px; background: #fffafc; }
  .entry h3 { font-size: 18px; font-weight: 800; }
  .meta { font-size: 15px; color: var(--muted); margin-top: 2px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 8px; margin-top: 10px; }
  .grid div { background: var(--pink-soft); border-radius: 10px; padding: 8px 10px; font-size: 15px; }
  .grid b { display: block; font-size: 14px; color: var(--muted); font-weight: 600; }
  pre { white-space: pre-wrap; word-break: break-word; font-size: 14px; font-family: ui-monospace, monospace; margin-top: 10px; background: #fff; border: 1px solid var(--pink-border); border-radius: 10px; padding: 10px; max-height: 320px; overflow: auto; }
  .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 14px; font-weight: 800; }
  .b-green { background: var(--green-bg); color: var(--green); }
  .b-orange { background: var(--orange-bg); color: var(--orange); }
  .b-red { background: var(--red-bg); color: var(--red); }
  .hint { font-size: 15px; color: var(--muted); margin-top: 8px; }
</style>
</head>
<body>
<header>
  <h1>Food Check logs</h1>
  <div class="sub">Last 30 analyses 最後 30 次分析記錄</div>
</header>
<main>
  <div class="card">
    <div class="label">Backend API key 後端密鑰</div>
    <div class="row">
      <input type="password" id="key" placeholder="fc_..." autocomplete="off">
      <button id="load">Load 載入</button>
      <button id="clear">Clear 清除</button>
    </div>
    <div class="hint">The key stays in this browser tab only (sessionStorage). 密鑰只保留在此分頁的 sessionStorage。</div>
    <div class="status" id="status"></div>
  </div>
  <div class="card">
    <div class="label">Entries 記錄</div>
    <div id="entries"><p class="hint">Enter the key and press Load. 輸入密鑰後按「載入」。</p></div>
  </div>
</main>
<script>
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var KEY = 'fc_log_key';

  function showStatus(msg, kind) {
    var el = $('status');
    el.textContent = msg;
    el.className = 'status show' + (kind ? ' ' + kind : '');
  }

  function render(entries) {
    if (!entries.length) {
      $('entries').innerHTML = '<p class="hint">No log entries yet. 暫時沒有記錄。</p>';
      return;
    }
    var html = '';
    entries.forEach(function (e) {
      var verdict = e.overall || 'unknown';
      var cls = verdict === 'suitable' ? 'b-green' : (verdict === 'caution' ? 'b-orange' : 'b-red');
      html += '<div class="entry">'
        + '<h3>' + escapeHtml(e.dish || '(no dish)') + ' <span class="badge ' + cls + '">' + escapeHtml(verdict) + '</span></h3>'
        + '<div class="meta">' + escapeHtml(e.datetime || '') + ' &middot; ' + escapeHtml(e.source || '') + ' &middot; ' + escapeHtml(e.level || '') + ' &middot; ' + escapeHtml(e.model || '') + ' (' + escapeHtml(e.provider || '') + ')</div>'
        + '<div class="grid">'
        + '<div><b>Duration 耗時</b>' + escapeHtml(String(e.duration_ms || 0)) + ' ms</div>'
        + '<div><b>VLM time 模型耗時</b>' + escapeHtml(String(e.vlm_ms || 0)) + ' ms</div>'
        + '<div><b>Tokens 詞元</b>' + escapeHtml(String((e.tokens || {}).total || 0)) + '</div>'
        + '<div><b>Speed 速度</b>' + escapeHtml(String(e.tokens_per_second || 0)) + ' t/s</div>'
        + '<div><b>Food 是否食物</b>' + escapeHtml(e.is_food === false ? 'no' : 'yes') + '</div>'
        + '<div><b>Edible 可食用</b>' + escapeHtml(e.is_edible === false ? 'no' : 'yes') + '</div>'
        + '</div>'
        + '<pre>' + escapeHtml(JSON.stringify(e.result || {}, null, 2)) + '</pre>'
        + '</div>';
    });
    $('entries').innerHTML = html;
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = String(s == null ? '' : s);
    return d.innerHTML;
  }

  function load() {
    var key = $('key').value.trim();
    if (!key) {
      showStatus('Enter the API key. 請輸入密鑰。', 'err');
      return;
    }
    try { sessionStorage.setItem(KEY, key); } catch (e) {}
    showStatus('Loading… 載入中…');
    fetch('/log/api.php', { headers: { 'X-API-Key': key, 'Accept': 'application/json' }, cache: 'no-store' })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
      .then(function (res) {
        if (!res.ok) {
          showStatus((res.data && res.data.error && res.data.error.message) || 'Failed 失敗', 'err');
          return;
        }
        showStatus('Loaded ' + res.data.count + ' entries. 已載入 ' + res.data.count + ' 筆記錄。', 'ok');
        render(res.data.entries || []);
      })
      .catch(function (err) { showStatus(String(err.message || err), 'err'); });
  }

  $('load').onclick = load;
  $('clear').onclick = function () {
    try { sessionStorage.removeItem(KEY); } catch (e) {}
    $('key').value = '';
    $('entries').innerHTML = '<p class="hint">Enter the key and press Load. 輸入密鑰後按「載入」。</p>';
    showStatus('Cleared. 已清除。');
  };
  $('key').addEventListener('keydown', function (e) { if (e.key === 'Enter') load(); });

  try {
    var saved = sessionStorage.getItem(KEY);
    if (saved) { $('key').value = saved; load(); }
  } catch (e) {}
})();
</script>
</body>
</html>
