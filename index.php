<?php
declare(strict_types=1);

/**
 * Food Check web app.
 *
 * The page is served as PHP so it can embed a short-lived signed token for
 * /vlm-process.php. The long-lived API key never reaches the browser.
 */

$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

try {
    $fcToken = fc_token_issue($config);
} catch (Throwable $fcError) {
    error_log('food-check: could not issue token: ' . $fcError->getMessage());
    $fcToken = ['token' => '', 'expires_at' => 0];
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#d81b60">
<meta name="description" content="Food texture checker for people with swallowing difficulty. 吞嚥困難人士的食物質地檢查工具。">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Food Check">
<link rel="manifest" href="/manifest.json">
<link rel="icon" type="image/svg+xml" href="/icon.svg?v=2">
<link rel="icon" type="image/png" sizes="192x192" href="/icon-192.png?v=2">
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">
<title>Food Texture Checker 食物質地檢查器</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #fff0f6; --card: #ffffff; --text: #2d1b26; --muted: #7a5567;
    --accent: #d81b60; --accent-dark: #a0004a;
    --pink-soft: #ffe4ef; --pink-border: #f2b8cf;
    --green: #0b7a4b; --green-bg: #e2f3e9;
    --orange: #b45309; --orange-bg: #fdf0dd;
    --red: #b91c1c; --red-bg: #fde8e8;
    --border: #f2b8cf;
  }
  html, body { height: 100%; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang HK", "PingFang SC", "Microsoft JhengHei", sans-serif;
    background: linear-gradient(180deg, #ffe4ef 0, #fff0f6 280px);
    color: var(--text);
    font-size: 20px; line-height: 1.55; padding-bottom: 90px;
    min-height: 100vh; min-height: 100dvh;
    overscroll-behavior-y: none;
    -webkit-tap-highlight-color: transparent;
  }
  header {
    background: linear-gradient(135deg, #d81b60, #ad1457);
    color: #fff;
    padding: 14px 16px;
    padding-top: calc(14px + env(safe-area-inset-top));
    padding-right: calc(16px + env(safe-area-inset-right));
    padding-left: calc(16px + env(safe-area-inset-left));
    position: sticky; top: 0; z-index: 10;
    box-shadow: 0 3px 12px rgba(173, 20, 87, .28);
  }
  .brand { display: flex; align-items: center; gap: 12px; max-width: 640px; margin: 0 auto; }
  .fs-btn {
    margin-left: auto; background: rgba(255,255,255,.22); border: none; color: #fff;
    font-size: 20px; width: 46px; height: 46px; border-radius: 12px; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
  }
  .fs-btn:active { transform: scale(.94); }
  .app-icon { width: 52px; height: 52px; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.2); background: #fff; }
  header h1 { font-size: 24px; font-weight: 800; line-height: 1.15; }
  header .sub { font-size: 15px; opacity: .95; }
  main {
    padding: 14px; max-width: 640px; margin: 0 auto;
    padding-bottom: calc(90px + env(safe-area-inset-bottom));
    min-height: calc(100dvh - 92px);
  }
  .card {
    background: var(--card); border: 1px solid var(--pink-border);
    border-radius: 18px; padding: 16px; margin-bottom: 14px;
    box-shadow: 0 3px 12px rgba(216, 27, 96, .10);
  }
  .camera-box {
    position: relative; background: #1a1a1a; border-radius: 18px; overflow: hidden;
    width: min(100%, 46vh); margin: 0 auto; aspect-ratio: 3/4; max-height: 62vh;
    cursor: pointer; touch-action: manipulation; box-shadow: 0 4px 16px rgba(173,20,87,.22);
  }
  .camera-box video { width: 100%; height: 100%; object-fit: cover; display: block; }
  .camera-box:focus-visible { outline: 4px solid var(--accent); outline-offset: 3px; }
  .camera-box.off {
    display: flex; align-items: center; justify-content: center;
    color: var(--muted); flex-direction: column; gap: 10px;
    font-size: 17px; text-align: center; cursor: default;
    background: var(--pink-soft); border: 2px dashed var(--pink-border);
  }
  .tap-hint {
    position: absolute; left: 50%; bottom: 12px; transform: translateX(-50%);
    background: rgba(255,255,255,.94); color: var(--accent-dark);
    padding: 8px 14px; border-radius: 999px; font-size: 16px; font-weight: 800;
    max-width: calc(100% - 16px); white-space: normal; text-align: center;
    pointer-events: none; box-shadow: 0 2px 8px rgba(0,0,0,.22);
  }
  .cam-icon { width: 64px; height: 64px; fill: var(--accent); opacity: .8; }
  .btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    border: none; border-radius: 14px; padding: 16px 18px;
    font-size: 20px; font-weight: 700; cursor: pointer; min-height: 60px;
    line-height: 1.2;
  }
  .btn:active { transform: scale(.98); }
  .btn:disabled { opacity: .5; cursor: not-allowed; }
  .btn-primary { background: var(--accent); color: #fff; width: 100%; box-shadow: 0 3px 10px rgba(216,27,96,.28); }
  .btn-primary:hover { background: var(--accent-dark); }
  .btn-secondary { background: var(--pink-soft); color: var(--accent-dark); width: 100%; border: 2px solid var(--pink-border); }
  .btn-row { display: flex; gap: 10px; margin-top: 10px; }
  .btn-row .btn { flex: 1; }
  .label { font-size: 17px; font-weight: 800; color: var(--accent-dark); margin: 6px 0 8px; }
  .iddsi { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .iddsi button {
    padding: 16px 10px; border-radius: 14px; border: 2px solid var(--pink-border);
    background: #fff; font-size: 17px; font-weight: 700; cursor: pointer;
    line-height: 1.3; color: var(--text);
  }
  .iddsi button small { display: block; font-weight: 400; font-size: 14px; color: var(--muted); margin-top: 2px; }
  .iddsi button.active { border-color: var(--accent); background: var(--pink-soft); color: var(--accent-dark); box-shadow: inset 0 0 0 2px rgba(216,27,96,.12); }
  #result { font-size: 19px; }
  .verdict {
    padding: 16px; border-radius: 14px; font-size: 22px; font-weight: 800; margin-bottom: 14px;
    display: flex; align-items: center; gap: 10px; line-height: 1.3;
  }
  .v-green { background: var(--green-bg); color: var(--green); }
  .v-orange { background: var(--orange-bg); color: var(--orange); }
  .v-red { background: var(--red-bg); color: var(--red); }
  .item { border: 1px solid var(--pink-border); border-radius: 14px; padding: 12px 14px; margin-bottom: 12px; background: #fffafc; }
  .item .name { font-weight: 800; font-size: 19px; }
  .item .zh { color: var(--muted); font-size: 17px; }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 15px; font-weight: 800; margin-left: 6px; vertical-align: middle; }
  .b-green { background: var(--green-bg); color: var(--green); }
  .b-orange { background: var(--orange-bg); color: var(--orange); }
  .b-red { background: var(--red-bg); color: var(--red); }
  .item .detail { margin-top: 8px; font-size: 17px; color: #4a3040; }
  .item .detail .zh { color: var(--muted); font-size: 16px; }
  .hint {
    font-size: 20px; color: var(--text); background: var(--pink-soft);
    border-left: 5px solid var(--accent); border-radius: 12px;
    padding: 12px 14px; margin-top: 12px; line-height: 1.5; font-weight: 600;
  }
  .status { font-size: 17px; color: var(--accent-dark); background: var(--pink-soft); border-radius: 12px; padding: 12px 14px; margin-top: 10px; display: none; }
  .status.show { display: block; }
  .status.err { background: var(--red-bg); color: var(--red); }
  pre.raw { white-space: pre-wrap; word-break: break-word; font-size: 17px; font-family: inherit; }
  .disclaimer { font-size: 15px; color: var(--red); margin-top: 12px; font-weight: 700; }
  .not-food {
    background: #fff1f1; border: 5px solid #e00000; border-radius: 18px;
    padding: 20px 14px; margin-bottom: 14px; text-align: center;
  }
  .big-cross { width: 160px; height: 160px; max-width: 52vw; display: block; margin: 0 auto 8px; fill: #e00000; filter: drop-shadow(0 0 8px rgba(224,0,0,.35)); }
  .not-food-title { color: #c00000; font-size: 30px; font-weight: 800; line-height: 1.2; }
  .not-food-title .zh { display: block; font-size: 24px; }
  .not-food-reason { color: #7a0000; font-size: 18px; margin-top: 12px; }
  .not-food-reason .zh { display: block; color: #9a0000; font-size: 17px; }
  .mods { margin: 0 0 12px 24px; }
  .mods li { font-size: 18px; margin: 10px 0; color: #3d2433; }
  .mods li .zh { display: block; color: var(--muted); font-size: 17px; }
  .chips { display: flex; flex-wrap: wrap; gap: 8px; margin: 6px 0 10px; }
  .chip { display: inline-flex; align-items: center; gap: 4px; padding: 6px 10px; border-radius: 999px; font-size: 16px; font-weight: 700; line-height: 1.2; }
  .chip.cat { background: var(--pink-soft); color: var(--accent-dark); border: 1px solid var(--pink-border); }
  .chip.hazard { background: var(--orange-bg); color: var(--orange); }
  .chip.allergen { background: var(--red-bg); color: var(--red); }
  .chip-label { font-size: 15px; font-weight: 800; color: var(--muted); align-self: center; }
  .chip-row { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; align-items: center; }
  .item-cat { display: inline-block; margin-top: 4px; font-size: 16px; font-weight: 700; color: var(--accent-dark); }
  .allergy-warning { background: #fff4e5; border: 3px solid #f0a35e; border-radius: 14px; padding: 12px 14px; margin: 10px 0 14px; color: #8a4b00; }
  .allergy-warning strong { display: block; font-size: 18px; margin-bottom: 4px; }
  .allergy-warning .chips { margin: 6px 0; }
  .allergy-sub { font-size: 15px; font-weight: 600; color: #8a4b00; }
  .health-notes { margin: 4px 0 14px; }
  .health-item { border: 2px solid var(--pink-border); border-radius: 14px; padding: 12px 14px; margin-bottom: 10px; background: #fffafc; }
  .health-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 4px; }
  .health-cond { font-size: 18px; font-weight: 800; color: var(--text); }
  .health-reason { font-size: 17px; color: #4a3040; }
  .health-reason .zh { display: block; color: var(--muted); font-size: 16px; }
  .health-disclaimer { font-size: 15px; color: var(--red); font-weight: 700; margin-top: 6px; line-height: 1.45; }
  .allergy-note { font-size: 16px; color: var(--red); margin-top: 8px; font-weight: 700; line-height: 1.45; }
  .tts-row { display: flex; gap: 10px; margin: 14px 0 4px; }
  .tts-row .btn { flex: 1; min-height: 58px; font-size: 18px; }
  .hidden { display: none !important; }
  #previewImg { width: 100%; border-radius: 14px; display: block; margin-bottom: 12px; border: 2px solid var(--pink-border); }
</style>
</head>
<body>
<header>
  <div class="brand">
    <img src="/icon-192.png?v=2" alt="" class="app-icon">
    <div>
      <h1>Food Texture Checker</h1>
      <div class="sub">食物質地檢查 · 吞嚥友好</div>
    </div>
    <button class="fs-btn" id="btnFullscreen" type="button" title="Full screen 全屏" aria-label="Full screen 全屏">⛶</button>
  </div>
</header>
<main>
  <!-- Diet level selector -->
  <div class="card">
    <div class="label">Diet level 飲食級別 (IDDSI)</div>
    <div class="iddsi" id="iddsi">
      <button data-level="liquid">Liquid 流質<small>Drinks only</small></button>
      <button data-level="puree">Puréed 糊狀<small>Very smooth</small></button>
      <button data-level="minced">Minced &amp; Moist 碎濕狀<small>Soft lumps</small></button>
      <button data-level="soft">Soft 軟食<small>Soft solids</small></button>
    </div>
  </div>

  <!-- Camera -->
  <div class="camera-box off hidden" id="camBox" role="button" tabindex="0" aria-label="Tap to capture a food photo 點按拍攝食物照片">
    <video id="video" playsinline autoplay muted class="hidden"></video>
    <div class="tap-hint">📸 Tap to capture 點按拍攝</div>
    <div id="camPlaceholder">
      <svg class="cam-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3 7.5 5H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3.5L15 3H9zm3 5a5 5 0 1 1 0 10 5 5 0 0 1 0-10zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/></svg>
      <div>Camera is closed<br>相機已關閉</div>
    </div>
  </div>
  <div class="btn-row">
    <button class="btn btn-secondary" id="btnCam">📷 Open camera 開啟相機</button>
    <button class="btn btn-primary" id="btnCapture" disabled>📸 Check food 檢查食物</button>
  </div>
  <div class="hint">Tip: make sure all dishes are in frame with a plain background. Tap the camera or press Check food to capture. 提示：請將整盤食物放入畫面，背景簡潔更容易識別。點按相機或按「檢查食物」即可拍攝。</div>
  <div class="status" id="status"></div>

  <!-- Result -->
  <div class="card hidden" id="resultCard" aria-live="polite">
    <img id="previewImg" alt="captured food">
    <div id="result"></div>
    <div class="tts-row hidden" id="ttsRow">
      <button class="btn btn-secondary" id="btnRead" type="button">🔊 Read result 朗讀結果</button>
      <button class="btn btn-secondary" id="btnStopRead" type="button">⏹ Stop 停止</button>
    </div>
    <div class="disclaimer">⚠️ This is a visual aid only. It cannot confirm ingredients or allergens. Always follow your doctor or dietitian/nutritionist. 此結果僅供參考，無法確認食材或致敏物。請遵從醫生或營養師的建議。</div>
    <div class="allergy-note">If you have a food allergy, confirm ingredients with the kitchen and follow your allergy plan. 如有食物過敏，請向廚房確認食材並遵從過敏處理計劃。</div>
  </div>
</main>

<script>
window.FC_TOKEN = <?= json_encode($fcToken['token']) ?>;
window.FC_TOKEN_EXP = <?= (int) $fcToken['expires_at'] ?>;
</script>
<script>
(function () {
  'use strict';

  // ---------- IDDSI ----------
  var currentLevel = 'soft';

  // ---------- Signed API token ----------
  // The server hands the page a short-lived signed token. It is refreshed
  // before it expires, whenever the tab becomes visible again, and right
  // before every request, so an idle tab never breaks.
  var tokenValue = window.FC_TOKEN || '';
  var tokenExpires = (window.FC_TOKEN_EXP || 0) * 1000;
  var tokenPromise = null;
  var TOKEN_MARGIN = 30000;

  function tokenFresh() {
    return !!tokenValue && Date.now() < tokenExpires - TOKEN_MARGIN;
  }

  function refreshToken() {
    if (tokenPromise) return tokenPromise;
    tokenPromise = fetch('/token.php', {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store'
    })
      .then(function (r) {
        return r.json().then(function (d) { return { ok: r.ok, data: d }; });
      })
      .then(function (res) {
        if (!res.ok || !res.data || !res.data.token) {
          throw new Error((res.data && res.data.error && res.data.error.message) || 'Token request failed.');
        }
        tokenValue = res.data.token;
        tokenExpires = (res.data.expires_at || 0) * 1000;
        return tokenValue;
      })
      .finally(function () { tokenPromise = null; });
    return tokenPromise;
  }

  function ensureToken() {
    if (tokenFresh()) return Promise.resolve(tokenValue);
    return refreshToken();
  }

  function keepTokenAlive() {
    if (!document.hidden) ensureToken().catch(function () {});
  }

  document.addEventListener('visibilitychange', keepTokenAlive);
  window.addEventListener('focus', keepTokenAlive);
  window.addEventListener('pageshow', keepTokenAlive);
  window.addEventListener('online', keepTokenAlive);
  setInterval(keepTokenAlive, 4 * 60 * 1000);

  var $ = function (id) { return document.getElementById(id); };
  var video = $('video'), camBox = $('camBox'), camPlaceholder = $('camPlaceholder');
  var btnCam = $('btnCam'), btnCapture = $('btnCapture'), statusEl = $('status');
  var stream = null, busy = false;

  // ---------- IDDSI UI ----------
  $('iddsi').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    document.querySelectorAll('#iddsi button').forEach(function (x) { x.classList.remove('active'); });
    b.classList.add('active'); currentLevel = b.getAttribute('data-level');
  });
  document.querySelector('#iddsi button[data-level="soft"]').classList.add('active');

  // ---------- Status ----------
  var statusTimer = null;
  function showStatus(msg, isErr) {
    statusEl.textContent = msg;
    statusEl.classList.toggle('err', !!isErr);
    statusEl.classList.add('show');
    clearTimeout(statusTimer);
    if (!isErr) statusTimer = setTimeout(function () { statusEl.classList.remove('show'); }, 4000);
  }

  // ---------- Camera ----------
  async function startCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      showStatus('Camera not supported in this browser. 此瀏覽器不支援相機。', true); return;
    }
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1920 } },
        audio: false
      });
      video.srcObject = stream;
      await video.play();
      camBox.classList.remove('hidden');
      video.classList.remove('hidden');
      camBox.classList.remove('off'); camPlaceholder.style.display = 'none';
      btnCam.textContent = '⏹ Close camera 關閉相機';
      btnCapture.disabled = false;
      stopSpeaking();
    } catch (err) {
      stopCamera();
      showStatus('Camera error: ' + err.message + ' 相機錯誤。', true);
    }
  }
  function stopCamera() {
    if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    video.srcObject = null;
    video.classList.add('hidden');
    camBox.classList.add('off');
    camBox.classList.add('hidden');
    camPlaceholder.style.display = '';
    btnCam.textContent = '📷 Open camera 開啟相機';
    btnCapture.disabled = true;
    stopSpeaking();
  }
  btnCam.onclick = function () { stream ? stopCamera() : startCamera(); };

  // ---------- Capture (the PHP backend does the final <=1000px resize) ----------
  function captureAndResize() {
    var vw = video.videoWidth, vh = video.videoHeight;
    if (!vw || !vh) { showStatus('Camera not ready. 相機未就緒。', true); return null; }
    var MAX = 1920; // upload cap; backend shrinks to 1000px for the VLM
    var scale = Math.min(1, MAX / Math.max(vw, vh)); // never upscale
    var w = Math.round(vw * scale), h = Math.round(vh * scale);
    var c = document.createElement('canvas');
    c.width = w; c.height = h;
    var ctx = c.getContext('2d');
    ctx.imageSmoothingEnabled = true; ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(video, 0, 0, w, h);
    return c.toDataURL('image/jpeg', 0.9);
  }

  function captureAndAnalyze() {
    if (busy || !stream) return;
    var dataUrl = captureAndResize();
    if (!dataUrl) return;
    stopCamera();
    analyze(dataUrl);
  }

  $('btnCapture').onclick = captureAndAnalyze;
  camBox.addEventListener('click', captureAndAnalyze);
  camBox.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      captureAndAnalyze();
    }
  });

  // ---------- Backend call ----------
  async function analyze(dataUrl) {
    busy = true; btnCapture.disabled = true;
    btnCapture.textContent = 'Analyzing… 分析中…';
    showStatus('Analyzing… 正在分析…');
    try {
      var payload = { image: dataUrl, level: currentLevel, source: 'camera' };

      var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var timer = controller ? setTimeout(function () { controller.abort(); }, 150000) : null;

      var res;
      try {
        // Always use a fresh token: the page may have been idle for a while.
        var token = await ensureToken();
        res = await fetch('/vlm-process.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-VLM-Token': token },
          body: JSON.stringify(payload),
          signal: controller ? controller.signal : undefined
        });

        // A token can expire between the refresh and the request: retry once.
        if (res.status === 401) {
          tokenValue = '';
          var fresh = await refreshToken();
          res = await fetch('/vlm-process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-VLM-Token': fresh },
            body: JSON.stringify(payload),
            signal: controller ? controller.signal : undefined
          });
        }
      } finally {
        if (timer) clearTimeout(timer);
      }

      var text = await res.text();
      var data = null;
      try { data = JSON.parse(text); } catch (e) {}

      if (!res.ok || !data || data.ok !== true) {
        var msg = data && data.error && data.error.message
          ? data.error.message
          : ('API ' + res.status + ': ' + text.slice(0, 200));
        throw new Error(msg);
      }

      var j = data.result || (data.raw ? extractJson(data.raw) : null);
      renderResult(j, data.raw || '', dataUrl);
    } catch (err) {
      var message = err && err.name === 'AbortError' ? 'Request timed out. 請求逾時。' : err.message;
      showStatus('Analysis failed 分析失敗: ' + message, true);
    } finally {
      busy = false; btnCapture.disabled = !stream;
      btnCapture.textContent = '📸 Check food 檢查食物';
    }
  }

  // ---------- Parse + render ----------
  function extractJson(text) {
    var m = text.match(/\{[\s\S]*\}/); // tolerant: first { to last }
    if (!m) return null;
    try { return JSON.parse(m[0]); } catch (e) { return null; }
  }
  var VERDICT = {
    suitable:     { en: 'Suitable', zh: '適合', cls: 'green' },
    caution:      { en: 'Caution', zh: '小心', cls: 'orange' },
    not_suitable: { en: 'Not suitable', zh: '不適合', cls: 'red' }
  };
  var CATEGORY = {
    fish:      { en: 'Fish', zh: '魚類', icon: '🐟' },
    meat:      { en: 'Meat', zh: '肉類', icon: '🥩' },
    pork:      { en: 'Pork', zh: '豬肉', icon: '🥓' },
    poultry:   { en: 'Poultry', zh: '家禽', icon: '🍗' },
    seafood:   { en: 'Seafood', zh: '海鮮', icon: '🦐' },
    vegetable: { en: 'Vegetable', zh: '蔬菜', icon: '🥬' },
    fruit:     { en: 'Fruit', zh: '水果', icon: '🍎' },
    grain:     { en: 'Grain', zh: '穀物', icon: '🍚' },
    dairy:     { en: 'Dairy', zh: '奶類', icon: '🥛' },
    egg:       { en: 'Egg', zh: '蛋類', icon: '🥚' },
    legume:    { en: 'Legume', zh: '豆類', icon: '🫘' },
    nut:       { en: 'Nut', zh: '果仁', icon: '🥜' },
    other:     { en: 'Other', zh: '其他', icon: '🍽️' }
  };
  var HAZARD = {
    bones:           { en: 'Bones', zh: '骨頭' },
    small_bones:     { en: 'Small bones', zh: '細骨' },
    fish_bones:      { en: 'Fish bones', zh: '魚骨' },
    shell_fragments: { en: 'Shell fragments', zh: '殼碎片' },
    hard_fragments:  { en: 'Hard fragments', zh: '硬碎片' },
    seeds:           { en: 'Seeds', zh: '種子' },
    skin:            { en: 'Skin', zh: '外皮' },
    cartilage:       { en: 'Cartilage', zh: '軟骨' },
    pits:            { en: 'Pits', zh: '果核' },
    spines:          { en: 'Spines', zh: '刺' },
    scales:          { en: 'Scales', zh: '魚鱗' },
    nuts:            { en: 'Nuts', zh: '果仁' }
  };
  var ALLERGEN = {
    milk:      { en: 'Milk', zh: '奶類' },
    egg:       { en: 'Egg', zh: '蛋類' },
    fish:      { en: 'Fish', zh: '魚類' },
    shellfish: { en: 'Shellfish', zh: '甲殼類' },
    peanut:    { en: 'Peanut', zh: '花生' },
    tree_nut:  { en: 'Tree nuts', zh: '樹堅果' },
    soy:       { en: 'Soy', zh: '大豆' },
    wheat:     { en: 'Wheat', zh: '小麥' },
    sesame:    { en: 'Sesame', zh: '芝麻' }
  };
  var CONDITION = {
    diabetes:        { en: 'Diabetes', zh: '糖尿病' },
    heart_disease:   { en: 'Heart disease', zh: '心臟病' },
    stroke:          { en: 'Stroke', zh: '中風' },
    kidney_disease:  { en: 'Kidney disease', zh: '腎病' },
    liver_disease:   { en: 'Liver disease', zh: '肝病' },
    hypertension:    { en: 'High blood pressure', zh: '高血壓' },
    high_cholesterol:{ en: 'High cholesterol', zh: '高膽固醇' },
    gout:            { en: 'Gout', zh: '痛風' }
  };
  var HEALTH_LEVEL = {
    caution: { en: 'Caution', zh: '注意', cls: 'orange' },
    avoid:   { en: 'Avoid', zh: '避免', cls: 'red' },
    unknown: { en: 'Unclear', zh: '不確定', cls: 'orange' }
  };
  function labelFor(map, key) {
    var k = String(key || '').toLowerCase();
    if (map[k]) return map[k];
    var pretty = k.replace(/_/g, ' ');
    pretty = pretty.replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    return { en: pretty || 'Unknown', zh: '' };
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
  function isNo(v) {
    if (v === false || v === 0 || v === '0') return true;
    var s = String(v).toLowerCase().trim();
    return s === 'false' || s === 'no' || s === 'not food' || s === 'non-food'
      || s === 'nonfood' || s === 'not_food' || s === 'inedible' || s === 'not edible'
      || s === 'unsafe';
  }

  // ---------- Text to speech ----------
  var ttsSupported = ('speechSynthesis' in window) && ('SpeechSynthesisUtterance' in window);
  var lastSpeech = null;
  var ttsTimer = null;

  function stopSpeaking() {
    if (!ttsSupported) return;
    clearTimeout(ttsTimer);
    window.speechSynthesis.cancel();
    $('btnRead').textContent = '🔊 Read result 朗讀結果';
  }

  function speakItems(items) {
    if (!ttsSupported || !items || !items.length) return;
    clearTimeout(ttsTimer);
    window.speechSynthesis.cancel();
    var lastItem = items[items.length - 1];

    items.forEach(function (item) {
      var u = new SpeechSynthesisUtterance(item.text);
      var isLast = item === lastItem;
      u.lang = item.lang || 'en-US';
      u.rate = (item.lang || '').indexOf('zh') === 0 ? 0.85 : 0.9;
      u.pitch = 1;
      u.volume = 1;
      u.onstart = function () { $('btnRead').textContent = '🔊 Reading… 朗讀中…'; };
      u.onend = function () { if (isLast) $('btnRead').textContent = '🔊 Read again 再朗讀'; };
      u.onerror = function () { if (isLast) $('btnRead').textContent = '🔊 Read result 朗讀結果'; };
      window.speechSynthesis.speak(u);
    });
  }

  function buildSpeech(j) {
    if (!j) return null;
    var out = [];
    var notFood = isNo(j.is_food) || isNo(j.is_edible);

    if (notFood) {
      out.push({
        lang: 'en-US',
        text: 'Warning. Do not eat. ' + (j.warning_en || j.overall_en
          || 'This does not appear to be food, or it may not be safe to eat.')
      });
      out.push({
        lang: 'zh-HK',
        text: '警告。請勿食用。' + (j.warning_zh || j.overall_zh
          || '這似乎不是食物，或可能不安全。')
      });
      return out;
    }

    var ov = VERDICT[j.overall] || VERDICT.caution;
    out.push({
      lang: 'en-US',
      text: (j.dish ? 'Result for ' + j.dish + '. ' : '') + ov.en + '. ' + (j.overall_en || '')
    });
    out.push({
      lang: 'zh-HK',
      text: (j.dish_zh ? j.dish_zh + '。' : '') + ov.zh + '。' + (j.overall_zh || '')
    });

    var items = Array.isArray(j.items) ? j.items : [];
    var itemEn = [], itemZh = [];
    items.forEach(function (it) {
      if (!it) return;
      var v = VERDICT[it.verdict] || VERDICT.caution;
      if (it.name) itemEn.push(it.name + ', ' + v.en);
      if (it.name_zh) itemZh.push(it.name_zh + '，' + v.zh);
    });
    if (itemEn.length) out.push({ lang: 'en-US', text: 'Ingredients. ' + itemEn.join('. ') });
    if (itemZh.length) out.push({ lang: 'zh-HK', text: '食材。' + itemZh.join('。') });

    var types = Array.isArray(j.food_types) ? j.food_types.slice() : [];
    if (!types.length) {
      items.forEach(function (it) {
        if (it && it.category && it.category !== 'other' && types.indexOf(it.category) === -1) {
          types.push(it.category);
        }
      });
    }
    if (types.length) {
      out.push({
        lang: 'en-US',
        text: 'Food types. ' + types.map(function (key) { return labelFor(CATEGORY, key).en; }).join(', ')
      });
      out.push({
        lang: 'zh-HK',
        text: '食物類型。' + types.map(function (key) {
          var c = labelFor(CATEGORY, key);
          return c.zh || c.en;
        }).join('。')
      });
    }

    var allergens = Array.isArray(j.allergens) ? j.allergens.slice() : [];
    if (!allergens.length) {
      items.forEach(function (it) {
        (it && Array.isArray(it.allergens) ? it.allergens : []).forEach(function (key) {
          if (allergens.indexOf(key) === -1) allergens.push(key);
        });
      });
    }
    if (allergens.length) {
      out.push({
        lang: 'en-US',
        text: 'Possible allergens. ' + allergens.map(function (key) { return labelFor(ALLERGEN, key).en; }).join(', ')
      });
      out.push({
        lang: 'zh-HK',
        text: '可能致敏物。' + allergens.map(function (key) {
          var a = labelFor(ALLERGEN, key);
          return a.zh || a.en;
        }).join('。')
      });
    }

    var hazards = [];
    items.forEach(function (it) {
      (it && Array.isArray(it.hazards) ? it.hazards : []).forEach(function (key) {
        if (hazards.indexOf(key) === -1) hazards.push(key);
      });
    });
    if (hazards.length) {
      out.push({
        lang: 'en-US',
        text: 'Possible hazards. ' + hazards.map(function (key) { return labelFor(HAZARD, key).en; }).join(', ')
      });
      out.push({
        lang: 'zh-HK',
        text: '可能風險。' + hazards.map(function (key) {
          var h = labelFor(HAZARD, key);
          return h.zh || h.en;
        }).join('。')
      });
    }

    var healthNotes = Array.isArray(j.health_notes) ? j.health_notes : [];
    if (healthNotes.length) {
      var healthEn = [], healthZh = [];
      healthNotes.forEach(function (note) {
        var c = CONDITION[note.condition] || labelFor(CONDITION, note.condition);
        var l = HEALTH_LEVEL[note.level] || HEALTH_LEVEL.caution;
        healthEn.push(c.en + ', ' + l.en + '. ' + (note.reason_en || ''));
        healthZh.push((c.zh || c.en) + '，' + (l.zh || l.en) + '。' + (note.reason_zh || ''));
      });
      out.push({ lang: 'en-US', text: 'Long-term health notes. ' + healthEn.join(' ') });
      out.push({ lang: 'zh-HK', text: '長期病患提示。' + healthZh.join(' ') });
    }

    var mods = Array.isArray(j.modifications) ? j.modifications : [];
    var en = [], zh = [];
    mods.forEach(function (m) {
      if (typeof m === 'string') { if (m) en.push(m); return; }
      if (!m) return;
      if (m.en) en.push(m.en);
      if (m.zh) zh.push(m.zh);
    });
    if (en.length) out.push({ lang: 'en-US', text: 'Ways to make it safer. ' + en.join('. ') });
    if (zh.length) out.push({ lang: 'zh-HK', text: '安全調整建議。' + zh.join('。') });

    return out;
  }

  function setupSpeech(j) {
    if (!ttsSupported) {
      $('ttsRow').classList.add('hidden');
      return;
    }

    stopSpeaking();
    lastSpeech = buildSpeech(j);
    if (lastSpeech && lastSpeech.length) {
      $('ttsRow').classList.remove('hidden');
      clearTimeout(ttsTimer);
      // A short delay lets the result render and scroll into view first.
      ttsTimer = setTimeout(function () { speakItems(lastSpeech); }, 400);
    } else {
      lastSpeech = null;
      $('ttsRow').classList.add('hidden');
    }
  }

  if (ttsSupported) {
    $('btnRead').addEventListener('click', function () {
      if (lastSpeech) speakItems(lastSpeech);
    });
    $('btnStopRead').addEventListener('click', stopSpeaking);
  }

  function renderResult(j, raw, dataUrl) {
    var html = '';
    $('previewImg').src = dataUrl;

    var notFood = j && (isNo(j.is_food) || isNo(j.is_edible));
    if (notFood) {
      var reasonEn = j.warning_en || j.overall_en || 'This does not appear to be food, or it may not be safe to eat.';
      var reasonZh = j.warning_zh || j.overall_zh || '這似乎不是食物，或可能不安全，請勿食用。';
      html += '<div class="not-food" role="alert">'
        + '<svg class="big-cross" viewBox="0 0 100 100" aria-hidden="true">'
        + '<path d="M16 6 L50 40 L84 6 L94 16 L60 50 L94 84 L84 94 L50 60 L16 94 L6 84 L40 50 L6 16 Z"/></svg>'
        + '<div class="not-food-title">Do not eat<span class="zh">請勿食用</span></div>'
        + '<div class="not-food-reason">' + esc(reasonEn) + '<span class="zh">' + esc(reasonZh) + '</span></div>'
        + '</div>';
    } else if (j && j.items) {
      var ov = VERDICT[j.overall] || VERDICT.caution;
      html += '<div class="verdict v-' + ov.cls + '">' + (ov.cls === 'green' ? '✅' : ov.cls === 'orange' ? '⚠️' : '🚫')
        + ' ' + esc(ov.en) + ' / ' + esc(ov.zh) + '</div>'
        + '<div style="margin-bottom:12px">' + esc(j.overall_en || '') + '<br><span style="color:var(--muted)">' + esc(j.overall_zh || '') + '</span></div>';

      var types = Array.isArray(j.food_types) ? j.food_types.slice() : [];
      if (!types.length) {
        (j.items || []).forEach(function (it) {
          if (it && it.category && it.category !== 'other' && types.indexOf(it.category) === -1) {
            types.push(it.category);
          }
        });
      }
      if (types.length) {
        html += '<div class="label">Food types 食物類型</div><div class="chips">';
        types.forEach(function (key) {
          var c = CATEGORY[key] || labelFor(CATEGORY, key);
          html += '<span class="chip cat">' + (c.icon || '🍽️') + ' ' + esc(c.en)
            + (c.zh ? ' / ' + esc(c.zh) : '') + '</span>';
        });
        html += '</div>';
      }

      var allergens = Array.isArray(j.allergens) ? j.allergens.slice() : [];
      if (!allergens.length) {
        (j.items || []).forEach(function (it) {
          (it && Array.isArray(it.allergens) ? it.allergens : []).forEach(function (key) {
            if (allergens.indexOf(key) === -1) allergens.push(key);
          });
        });
      }
      if (allergens.length) {
        html += '<div class="allergy-warning" role="alert"><strong>🚨 Possible allergens 可能致敏物</strong><div class="chips">';
        allergens.forEach(function (key) {
          var a = labelFor(ALLERGEN, key);
          html += '<span class="chip allergen">' + esc(a.en)
            + (a.zh ? ' / ' + esc(a.zh) : '') + '</span>';
        });
        html += '</div><div class="allergy-sub">Photo estimate only. Confirm with the kitchen. 僅供參考，請向廚房確認。</div></div>';
      }

      var healthNotes = Array.isArray(j.health_notes) ? j.health_notes : [];
      if (healthNotes.length) {
        html += '<div class="label">Long-term health notes 長期病患提示</div><div class="health-notes">';
        healthNotes.forEach(function (note) {
          var cond = CONDITION[note.condition] || labelFor(CONDITION, note.condition);
          var lvl = HEALTH_LEVEL[note.level] || HEALTH_LEVEL.caution;
          html += '<div class="health-item">'
            + '<div class="health-head"><span class="health-cond">' + esc(cond.en)
            + (cond.zh ? ' / ' + esc(cond.zh) : '') + '</span>'
            + '<span class="badge b-' + lvl.cls + '">' + esc(lvl.en) + ' / ' + esc(lvl.zh) + '</span></div>'
            + '<div class="health-reason">' + esc(note.reason_en || '')
            + (note.reason_zh ? '<span class="zh">' + esc(note.reason_zh) + '</span>' : '') + '</div>'
            + '</div>';
        });
        html += '</div><div class="health-disclaimer">General information only, not medical advice. Confirm with your doctor or dietitian. 僅供一般參考，並非醫療建議。請向醫生或營養師確認。</div>';
      }

      var mods = Array.isArray(j.modifications) ? j.modifications : [];
      if (mods.length) {
        html += '<div class="label">Ways to make it safer 安全調整建議</div><ul class="mods">';
        mods.forEach(function (m) {
          if (typeof m === 'string') {
            if (m) html += '<li>' + esc(m) + '</li>';
            return;
          }
          if (!m) return;
          var en = m.en || m.action_en || '';
          var zh = m.zh || m.action_zh || '';
          if (!en && !zh) return;
          html += '<li>' + (en ? esc(en) : '') + (zh ? '<span class="zh">' + esc(zh) + '</span>' : '') + '</li>';
        });
        html += '</ul>';
      }

      html += '<div class="label">Ingredients 食材明細</div>';
      (j.items || []).forEach(function (it) {
        var v = VERDICT[it.verdict] || VERDICT.caution;
        var cat = CATEGORY[it.category] || labelFor(CATEGORY, it.category);

        var hazardHtml = '';
        var hazards = Array.isArray(it.hazards) ? it.hazards : [];
        if (hazards.length) {
          hazardHtml = '<div class="chip-row"><span class="chip-label">Hazards 風險</span>';
          hazards.forEach(function (key) {
            var h = labelFor(HAZARD, key);
            hazardHtml += '<span class="chip hazard">' + esc(h.en)
              + (h.zh ? ' / ' + esc(h.zh) : '') + '</span>';
          });
          hazardHtml += '</div>';
        }

        var allergenHtml = '';
        var itemAllergens = Array.isArray(it.allergens) ? it.allergens : [];
        if (itemAllergens.length) {
          allergenHtml = '<div class="chip-row"><span class="chip-label">Allergens 致敏物</span>';
          itemAllergens.forEach(function (key) {
            var a = labelFor(ALLERGEN, key);
            allergenHtml += '<span class="chip allergen">' + esc(a.en)
              + (a.zh ? ' / ' + esc(a.zh) : '') + '</span>';
          });
          allergenHtml += '</div>';
        }

        html += '<div class="item">'
          + '<div class="name">' + esc(it.name || '') + '<span class="badge b-' + v.cls + '">' + esc(v.en) + ' / ' + esc(v.zh) + '</span></div>'
          + '<div class="zh">' + esc(it.name_zh || '') + '</div>'
          + '<div class="item-cat">' + (cat.icon || '🍽️') + ' ' + esc(cat.en)
          + (cat.zh ? ' / ' + esc(cat.zh) : '') + '</div>'
          + (it.texture ? '<div class="detail">Texture 質地: ' + esc(it.texture) + '<br><span class="zh">' + esc(it.texture_zh || '') + '</span></div>' : '')
          + (it.risk ? '<div class="detail">Risk 風險: ' + esc(it.risk) + '<br><span class="zh">' + esc(it.risk_zh || '') + '</span></div>' : '')
          + hazardHtml
          + allergenHtml
          + '</div>';
      });
    } else {
      html += '<pre class="raw">' + esc(raw || 'No result. 沒有結果。') + '</pre>';
    }

    $('result').innerHTML = html;
    $('resultCard').classList.remove('hidden');
    $('resultCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    showStatus('Done. 分析完成。');
    setupSpeech(j);
  }

  // ---------- Full screen ----------
  function isFullscreen() {
    if (document.fullscreenElement || document.webkitFullscreenElement) return true;
    if (window.matchMedia && window.matchMedia('(display-mode: fullscreen)').matches) return true;
    if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return true;
    return window.navigator.standalone === true;
  }

  function syncFullscreenButton() {
    $('btnFullscreen').classList.toggle('hidden', isFullscreen());
  }

  $('btnFullscreen').addEventListener('click', function () {
    var root = document.documentElement;
    var request = root.requestFullscreen || root.webkitRequestFullscreen || root.msRequestFullscreen;
    if (request) {
      Promise.resolve(request.call(root)).then(syncFullscreenButton).catch(function () {});
      return;
    }
    // iOS Safari has no fullscreen API on iPhone: offer the home-screen hint.
    showStatus('On iPhone, tap Share then "Add to Home Screen" for full screen. 在 iPhone 上請按「分享」→「加入主畫面」以全屏顯示。');
  });

  document.addEventListener('fullscreenchange', syncFullscreenButton);
  document.addEventListener('webkitfullscreenchange', syncFullscreenButton);
  window.addEventListener('orientationchange', function () { setTimeout(syncFullscreenButton, 200); });
  syncFullscreenButton();

  // ---------- Cleanup ----------
  window.addEventListener('pagehide', function () {
    stopCamera();
    stopSpeaking();
  });
})();
</script>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js').catch(function () {});
  });
}
</script>
</body>
</html>
