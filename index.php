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

// Build stamp: lets an open tab notice a new deployment and reload itself.
$fcBuild = 'b' . (string) @filemtime(__FILE__);
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
<script>
// Apply the saved profile before the first paint so the theme never flashes.
(function () {
  var SCALE = { xs: 0.78, s: 0.88, m: 1, l: 1.16, xl: 1.32, xxl: 1.5 };
  var THEMES = ['pink','rose','ocean','forest','grape','sunset','teal','slate','sepia','crimson','navy','dark','midnight','contrast'];
  var COLORS = ['default','dark','soft','warm','navy','green','maroon'];
  var FONTS = ['system','rounded','serif','mono','slab','humanist'];
  var p = {};
  try {
    p = JSON.parse(localStorage.getItem('fc_profile_v1') || '{}') || {};
  } catch (e) { p = {}; }
  // localStorage can throw (private mode, blocked cookies). The cookie mirror
  // is read separately so it still works when storage is unavailable.
  if (!p || !Object.keys(p).length) {
    try {
      var m = document.cookie.match(/(?:^|;\s*)fc_profile=([^;]*)/);
      if (m && m[1]) p = JSON.parse(decodeURIComponent(m[1])) || {};
    } catch (e) { p = {}; }
  }
  var root = document.documentElement;
  if (THEMES.indexOf(p.theme) >= 0) root.setAttribute('data-theme', p.theme);
  if (COLORS.indexOf(p.color) >= 0) root.setAttribute('data-color', p.color);
  if (FONTS.indexOf(p.font) >= 0) root.setAttribute('data-font', p.font);
  if (SCALE[p.size]) root.style.setProperty('--fs', SCALE[p.size]);

  // If the app was updated since this tab was opened, reload once so the new
  // code (settings, language, theme) is actually running.
  var BUILD = <?= json_encode($fcBuild) ?>;
  try {
    var prev = localStorage.getItem('fc_build');
    if (prev && prev !== BUILD && !sessionStorage.getItem('fc_reloaded')) {
      sessionStorage.setItem('fc_reloaded', '1');
      localStorage.setItem('fc_build', BUILD);
      location.reload();
    } else if (!prev) {
      localStorage.setItem('fc_build', BUILD);
    }
  } catch (e) { /* ignore */ }
})();
</script>
<title>Food Texture Checker 食物質地檢查器</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
    --fs: 1;
    --font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang HK", "Microsoft JhengHei", sans-serif;
    --bg: #fff0f6; --card: #ffffff; --text: #2d1b26; --muted: #7a5567;
    --accent: #d81b60; --accent-dark: #a0004a;
    --pink-soft: #ffe4ef; --pink-border: #f2b8cf;
    --green: #0b7a4b; --green-bg: #e2f3e9;
    --orange: #b45309; --orange-bg: #fdf0dd;
    --red: #b91c1c;   --red-bg: #fde8e8;
    --border: #f2b8cf;
    --grad-a: #d81b60; --grad-b: #ad1457;
    --item-bg: #fffafc; --hint-bg: #ffe4ef;
    --tint: #ffe4ef; --tint-border: #f2b8cf;
    --warn-bg: #fff4e5; --warn-border: #f0a35e; --warn-text: #8a4b00;
    --danger-bg: #fff1f1; --danger-text: #7a0000; --danger-title: #c00000;
    --shadow: rgba(216, 27, 96, .10); --shadow-strong: rgba(173, 20, 87, .22);
  }

  /* ---------- Themes (surface + accent in one choice) ---------- */
  html[data-theme="pink"] {
    --bg: #fff0f6; --card: #ffffff; --text: #2d1b26; --muted: #7a5567;
    --accent: #d81b60; --accent-dark: #a0004a;
    --pink-soft: #ffe4ef; --pink-border: #f2b8cf; --border: #f2b8cf;
    --grad-a: #d81b60; --grad-b: #ad1457;
    --item-bg: #fffafc; --hint-bg: #ffe4ef; --tint: #ffe4ef; --tint-border: #f2b8cf;
    --shadow: rgba(216, 27, 96, .10); --shadow-strong: rgba(173, 20, 87, .22);
  }
  html[data-theme="rose"] {
    --bg: #fff0f2; --card: #ffffff; --text: #3a1220; --muted: #8a4a5c;
    --accent: #c81e4a; --accent-dark: #961536;
    --pink-soft: #ffdde3; --pink-border: #f2b3bf; --border: #f2b3bf;
    --grad-a: #e0325f; --grad-b: #961536;
    --item-bg: #fff7f8; --hint-bg: #ffdde3; --tint: #ffdde3; --tint-border: #f2b3bf;
    --shadow: rgba(200, 30, 74, .12); --shadow-strong: rgba(150, 21, 54, .22);
  }
  html[data-theme="ocean"] {
    --bg: #eff6ff; --card: #ffffff; --text: #16243d; --muted: #4b6182;
    --accent: #1565c0; --accent-dark: #0d47a1;
    --pink-soft: #dbeafe; --pink-border: #bfd7f7; --border: #bfd7f7;
    --grad-a: #1565c0; --grad-b: #0d47a1;
    --item-bg: #f7fbff; --hint-bg: #dbeafe; --tint: #dbeafe; --tint-border: #bfd7f7;
    --shadow: rgba(21, 101, 192, .12); --shadow-strong: rgba(13, 71, 161, .22);
  }
  html[data-theme="forest"] {
    --bg: #eefaf3; --card: #ffffff; --text: #12291c; --muted: #4a6b57;
    --accent: #1b7f4b; --accent-dark: #0f5c33;
    --pink-soft: #d8f3e7; --pink-border: #b3e0cb; --border: #b3e0cb;
    --grad-a: #12925f; --grad-b: #0f5c33;
    --item-bg: #f6fdf9; --hint-bg: #d8f3e7; --tint: #d8f3e7; --tint-border: #b3e0cb;
    --shadow: rgba(18, 128, 92, .12); --shadow-strong: rgba(10, 90, 64, .22);
  }
  html[data-theme="grape"] {
    --bg: #f7f1ff; --card: #ffffff; --text: #241533; --muted: #6a5580;
    --accent: #7b2cbf; --accent-dark: #5b1f91;
    --pink-soft: #eddcff; --pink-border: #d3b8f0; --border: #d3b8f0;
    --grad-a: #8b3fd0; --grad-b: #5b1f91;
    --item-bg: #fbf7ff; --hint-bg: #eddcff; --tint: #eddcff; --tint-border: #d3b8f0;
    --shadow: rgba(123, 44, 191, .12); --shadow-strong: rgba(91, 31, 145, .22);
  }
  html[data-theme="sunset"] {
    --bg: #fff4ec; --card: #ffffff; --text: #40230f; --muted: #8a5a38;
    --accent: #e2560f; --accent-dark: #b3400a;
    --pink-soft: #ffe4d2; --pink-border: #f6c3a0; --border: #f6c3a0;
    --grad-a: #f07a22; --grad-b: #c2430a;
    --item-bg: #fffaf5; --hint-bg: #ffe4d2; --tint: #ffe4d2; --tint-border: #f6c3a0;
    --shadow: rgba(226, 86, 15, .12); --shadow-strong: rgba(179, 64, 10, .22);
  }
  html[data-theme="teal"] {
    --bg: #edfafa; --card: #ffffff; --text: #0d2e2f; --muted: #47706f;
    --accent: #0d7f80; --accent-dark: #08595a;
    --pink-soft: #d2f0f0; --pink-border: #a9dede; --border: #a9dede;
    --grad-a: #12999a; --grad-b: #08595a;
    --item-bg: #f5fdfd; --hint-bg: #d2f0f0; --tint: #d2f0f0; --tint-border: #a9dede;
    --shadow: rgba(13, 127, 128, .12); --shadow-strong: rgba(8, 89, 90, .22);
  }
  html[data-theme="slate"] {
    --bg: #eef1f6; --card: #ffffff; --text: #1f2b3a; --muted: #556478;
    --accent: #455a70; --accent-dark: #2e3f52;
    --pink-soft: #dde4ed; --pink-border: #bcc8d6; --border: #bcc8d6;
    --grad-a: #566d85; --grad-b: #2e3f52;
    --item-bg: #f6f8fb; --hint-bg: #dde4ed; --tint: #dde4ed; --tint-border: #bcc8d6;
    --shadow: rgba(69, 90, 112, .12); --shadow-strong: rgba(46, 63, 82, .22);
  }
  html[data-theme="sepia"] {
    --bg: #f8f0e4; --card: #fffdf9; --text: #3d2f1f; --muted: #7a6749;
    --accent: #a4622a; --accent-dark: #7c4519;
    --pink-soft: #f0e2cd; --pink-border: #dcc4a2; --border: #dcc4a2;
    --grad-a: #b8763a; --grad-b: #7c4519;
    --item-bg: #fdf8f0; --hint-bg: #f0e2cd; --tint: #f0e2cd; --tint-border: #dcc4a2;
    --shadow: rgba(164, 98, 42, .12); --shadow-strong: rgba(124, 69, 25, .22);
  }
  html[data-theme="crimson"] {
    --bg: #fff1f1; --card: #ffffff; --text: #3d1414; --muted: #8a4a4a;
    --accent: #c62828; --accent-dark: #8e1c1c;
    --pink-soft: #ffdede; --pink-border: #f3b3b3; --border: #f3b3b3;
    --grad-a: #d32f2f; --grad-b: #8e1c1c;
    --item-bg: #fff7f7; --hint-bg: #ffdede; --tint: #ffdede; --tint-border: #f3b3b3;
    --shadow: rgba(198, 40, 40, .12); --shadow-strong: rgba(142, 28, 28, .22);
  }
  html[data-theme="navy"] {
    --bg: #eef2f9; --card: #ffffff; --text: #17263f; --muted: #4c6183;
    --accent: #3d5a80; --accent-dark: #27405e;
    --pink-soft: #dfe7f1; --pink-border: #bcc9dc; --border: #bcc9dc;
    --grad-a: #4a6b96; --grad-b: #27405e;
    --item-bg: #f6f8fc; --hint-bg: #dfe7f1; --tint: #dfe7f1; --tint-border: #bcc9dc;
    --shadow: rgba(61, 90, 128, .12); --shadow-strong: rgba(39, 64, 94, .22);
  }
  html[data-theme="dark"] {
    --bg: #14121a; --card: #201d29; --text: #f2eef5; --muted: #a89fb4;
    --accent: #ff7fb0; --accent-dark: #ffb3d0;
    --pink-soft: #2e2739; --pink-border: #4a3f5a; --border: #3a3346;
    --green: #6ee7a8; --green-bg: #17301f;
    --orange: #ffc46b; --orange-bg: #33260f;
    --red: #ff8f8f;   --red-bg: #3a1c1c;
    --grad-a: #b03068; --grad-b: #7a1f48;
    --item-bg: #1c1926; --hint-bg: #2e2739; --tint: #2e2739; --tint-border: #4a3f5a;
    --warn-bg: #3a2a16; --warn-border: #b07a3a; --warn-text: #ffd8a8;
    --danger-bg: #3a1c1c; --danger-text: #ffc9c9; --danger-title: #ff8f8f;
    --shadow: rgba(0, 0, 0, .35); --shadow-strong: rgba(0, 0, 0, .5);
  }
  html[data-theme="midnight"] {
    --bg: #0b1220; --card: #141d2e; --text: #e8eef8; --muted: #9fb0c9;
    --accent: #7fb3ff; --accent-dark: #a9cbff;
    --pink-soft: #1b2740; --pink-border: #2d3d5c; --border: #26334a;
    --green: #6ee7a8; --green-bg: #10291d;
    --orange: #ffc46b; --orange-bg: #2e2210;
    --red: #ff8f8f;   --red-bg: #331616;
    --grad-a: #1e3a6b; --grad-b: #0d1b33;
    --item-bg: #101827; --hint-bg: #1b2740; --tint: #1b2740; --tint-border: #2d3d5c;
    --warn-bg: #2e2413; --warn-border: #a8762f; --warn-text: #ffd8a8;
    --danger-bg: #301717; --danger-text: #ffc9c9; --danger-title: #ff8f8f;
    --shadow: rgba(0, 0, 0, .4); --shadow-strong: rgba(0, 0, 0, .55);
  }
  html[data-theme="contrast"] {
    --bg: #ffffff; --card: #ffffff; --text: #000000; --muted: #000000;
    --accent: #0000cc; --accent-dark: #000099;
    --pink-soft: #ffff00; --pink-border: #000000; --border: #000000;
    --green: #006400; --green-bg: #e6ffe6;
    --orange: #8a3b00; --orange-bg: #ffe9d6;
    --red: #b00000;   --red-bg: #ffe0e0;
    --grad-a: #000000; --grad-b: #000000;
    --item-bg: #ffffff; --hint-bg: #ffff00; --tint: #ffff00; --tint-border: #000000;
    --warn-bg: #ffffcc; --warn-border: #000000; --warn-text: #5a2d00;
    --danger-bg: #ffe0e0; --danger-text: #7a0000; --danger-title: #b00000;
    --shadow: rgba(0, 0, 0, .2); --shadow-strong: rgba(0, 0, 0, .35);
  }

  /* ---------- Self-hosted rounded font (Latin only; CJK falls back) ---------- */
  @font-face {
    font-family: 'Nunito';
    font-style: normal;
    font-weight: 400 800;
    font-display: swap;
    src: url('/fonts/nunito-latin.woff2') format('woff2');
    unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
  }
  @font-face {
    font-family: 'Nunito';
    font-style: normal;
    font-weight: 400 800;
    font-display: swap;
    src: url('/fonts/nunito-latin-ext.woff2') format('woff2');
    unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C4, U+2113, U+2C60-2C7F, U+A720-A7FF;
  }

  /* ---------- Font family ---------- */
  /* system: the native UI font. */
  html[data-font="system"] { --font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang HK", "Microsoft JhengHei", sans-serif; }
  /* rounded: the self-hosted Nunito face (Latin); Chinese keeps the system face. */
  html[data-font="rounded"] { --font-family: "Nunito", "Quicksand", "Varela Round", ui-rounded, "PingFang HK", "Microsoft JhengHei", sans-serif; }
  /* serif: slab/old-style for Latin, Song style for Chinese. */
  html[data-font="serif"] { --font-family: Georgia, "Times New Roman", "Songti TC", "Noto Serif CJK TC", "PMingLiU", serif; }
  /* sans: neutral grotesque, no native UI styling. */
  /* mono: fixed pitch. */
  html[data-font="mono"] { --font-family: ui-monospace, "SF Mono", "DejaVu Sans Mono", Menlo, Consolas, monospace; }
  /* slab: heavy square serifs. */
  html[data-font="slab"] { --font-family: Rockwell, "Arvo", "Roboto Slab", "Courier New", "Songti TC", serif; }
  /* humanist: open, calligraphic forms. */
  html[data-font="humanist"] { --font-family: Optima, Candara, "Gill Sans", "Trebuchet MS", "PingFang HK", "Microsoft JhengHei", sans-serif; }
  /* condensed: narrow forms. */
  /* kai: brush-style Kai for Chinese, clean serif for Latin. */
  /* traditional: Ming/serif Chinese, Times for Latin. */

  /* ---------- Text colour ---------- */
  html[data-color="dark"] { --text: #101010; }
  html[data-color="soft"] { --text: #6b6b6b; }
  html[data-color="warm"] { --text: #4a2c1a; }
  html[data-color="navy"] { --text: #1d3357; }
  html[data-color="green"] { --text: #123d27; }
  html[data-color="maroon"] { --text: #5c1024; }
  html[data-theme="dark"] html[data-color="dark"] { --text: #f2eef5; }
  html[data-theme="dark"] html[data-color="soft"] { --text: #b8b0c2; }
  html[data-theme="dark"] html[data-color="warm"] { --text: #f0dcc8; }
  html[data-theme="dark"] html[data-color="navy"] { --text: #bcd0f0; }
  html[data-theme="dark"] html[data-color="green"] { --text: #b4e8ca; }
  html[data-theme="dark"] html[data-color="maroon"] { --text: #f2b6c6; }
  html[data-theme="midnight"] html[data-color="dark"] { --text: #e8eef8; }
  html[data-theme="midnight"] html[data-color="soft"] { --text: #a9b8cf; }
  html[data-theme="midnight"] html[data-color="warm"] { --text: #e8d6c0; }
  html[data-theme="midnight"] html[data-color="navy"] { --text: #b6cdf0; }
  html[data-theme="midnight"] html[data-color="green"] { --text: #b0e6c8; }
  html[data-theme="midnight"] html[data-color="maroon"] { --text: #f0b4c4; }
  html, body { height: 100%; }
  body {
    font-family: var(--font-family);
    background: linear-gradient(180deg, var(--pink-soft) 0, var(--bg) 280px);
    color: var(--text);
    font-size: calc(20px * var(--fs)); line-height: 1.55; padding-bottom: 90px;
    min-height: 100vh; min-height: 100dvh;
    overscroll-behavior-y: none;
    -webkit-tap-highlight-color: transparent;
  }
  header {
    background: linear-gradient(135deg, var(--grad-a), var(--grad-b));
    color: #fff;
    padding: 14px 16px;
    padding-top: calc(14px + env(safe-area-inset-top));
    padding-right: calc(16px + env(safe-area-inset-right));
    padding-left: calc(16px + env(safe-area-inset-left));
    position: sticky; top: 0; z-index: 10;
    box-shadow: 0 3px 12px var(--shadow-strong);
  }
  .brand { display: flex; align-items: center; gap: 12px; max-width: 640px; margin: 0 auto; }
  .fs-btn {
    margin-left: auto; background: rgba(255,255,255,.22); border: none; color: #fff;
    font-size: calc(20px * var(--fs)); width: 46px; height: 46px; border-radius: 12px; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
  }
  .fs-btn:active { transform: scale(.94); }
  .app-icon { width: 52px; height: 52px; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.2); background: #fff; }
  header h1 { font-size: calc(24px * var(--fs)); font-weight: 800; line-height: 1.15; }
  header .sub { font-size: calc(15px * var(--fs)); opacity: .95; }
  main {
    padding: 14px; max-width: 640px; margin: 0 auto;
    padding-bottom: calc(90px + env(safe-area-inset-bottom));
    min-height: calc(100dvh - 92px);
  }
  .card {
    background: var(--card); border: 1px solid var(--pink-border);
    border-radius: 18px; padding: 16px; margin-bottom: 14px;
    box-shadow: 0 3px 12px var(--shadow);
  }
  .camera-box {
    position: relative; background: #1a1a1a; border-radius: 18px; overflow: hidden;
    width: min(100%, 46vh); margin: 0 auto; aspect-ratio: 3/4; max-height: 62vh;
    cursor: pointer; touch-action: manipulation; box-shadow: 0 4px 16px var(--shadow-strong);
  }
  .camera-box video { width: 100%; height: 100%; object-fit: cover; display: block; }
  .camera-box:focus-visible { outline: 4px solid var(--accent); outline-offset: 3px; }
  .camera-box.off {
    display: flex; align-items: center; justify-content: center;
    color: var(--muted); flex-direction: column; gap: 10px;
    font-size: calc(17px * var(--fs)); text-align: center; cursor: default;
    background: var(--pink-soft); border: 2px dashed var(--pink-border);
  }
  .tap-hint {
    position: absolute; left: 50%; bottom: 12px; transform: translateX(-50%);
    background: rgba(255,255,255,.94); color: #7a0f38;
    padding: 8px 14px; border-radius: 999px; font-size: calc(16px * var(--fs)); font-weight: 800;
    max-width: calc(100% - 16px); white-space: normal; text-align: center;
    pointer-events: none; box-shadow: 0 2px 8px rgba(0,0,0,.22);
  }
  .cam-icon { width: 64px; height: 64px; fill: var(--accent); opacity: .8; }
  .btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    border: none; border-radius: 14px; padding: calc(16px * var(--fs)) calc(18px * var(--fs));
    font-size: calc(20px * var(--fs)); font-weight: 700; cursor: pointer; min-height: calc(60px * var(--fs));
    line-height: 1.2;
  }
  .btn:active { transform: scale(.98); }
  .btn:disabled { opacity: .5; cursor: not-allowed; }
  .btn-primary { background: var(--accent); color: #fff; width: 100%; box-shadow: 0 3px 10px rgba(216,27,96,.28); }
  .btn-primary:hover { background: var(--accent-dark); }
  .btn-secondary { background: var(--tint); color: var(--accent-dark); width: 100%; border: 2px solid var(--tint-border); }
  .btn-row { display: flex; gap: 10px; margin-top: 10px; }
  .btn-row .btn { flex: 1; }
  .label { font-size: calc(17px * var(--fs)); font-weight: 800; color: var(--accent-dark); margin: 6px 0 8px; }
  .iddsi { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .iddsi button {
    padding: 16px 10px; border-radius: 14px; border: 2px solid var(--pink-border);
    background: var(--card); font-size: calc(17px * var(--fs)); font-weight: 700; cursor: pointer;
    line-height: 1.3; color: var(--text);
  }
  .iddsi button small { display: block; font-weight: 400; font-size: calc(14px * var(--fs)); color: var(--muted); margin-top: 2px; }
  .iddsi button.active { border-color: var(--accent); background: var(--tint); color: var(--accent-dark); box-shadow: inset 0 0 0 2px rgba(216,27,96,.12); }
  #result { font-size: calc(19px * var(--fs)); }
  .verdict {
    padding: 16px; border-radius: 14px; font-size: calc(22px * var(--fs)); font-weight: 800; margin-bottom: 14px;
    display: flex; align-items: center; gap: 10px; line-height: 1.3;
  }
  .v-green { background: var(--green-bg); color: var(--green); }
  .v-orange { background: var(--orange-bg); color: var(--orange); }
  .v-red { background: var(--red-bg); color: var(--red); }
  .item { border: 1px solid var(--pink-border); border-radius: 14px; padding: 12px 14px; margin-bottom: 12px; background: var(--item-bg); }
  .item .name { font-weight: 800; font-size: calc(19px * var(--fs)); }
  .item .zh { color: var(--muted); font-size: calc(17px * var(--fs)); }
  .zh { color: var(--muted); }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: calc(15px * var(--fs)); font-weight: 800; margin-left: 6px; vertical-align: middle; }
  .b-green { background: var(--green-bg); color: var(--green); }
  .b-orange { background: var(--orange-bg); color: var(--orange); }
  .b-red { background: var(--red-bg); color: var(--red); }
  .item .detail { margin-top: 8px; font-size: calc(17px * var(--fs)); color: var(--text); }
  .item .detail .zh { color: var(--muted); font-size: calc(16px * var(--fs)); }
  .hint {
    font-size: calc(20px * var(--fs)); color: var(--text); background: var(--hint-bg);
    border-left: 5px solid var(--accent); border-radius: 12px;
    padding: 12px 14px; margin-top: 12px; line-height: 1.5; font-weight: 600;
  }
  .status { font-size: calc(17px * var(--fs)); color: var(--accent-dark); background: var(--tint); border-radius: 12px; padding: 12px 14px; margin-top: 10px; display: none; }
  .status.show { display: block; }
  .status.err { background: var(--red-bg); color: var(--red); }
  pre.raw { white-space: pre-wrap; word-break: break-word; font-size: calc(17px * var(--fs)); font-family: inherit; }
  .disclaimer { font-size: calc(15px * var(--fs)); color: var(--red); margin-top: 12px; font-weight: 700; }
  .not-food {
    background: var(--danger-bg); border: 5px solid #e00000; border-radius: 18px;
    padding: 20px 14px; margin-bottom: 14px; text-align: center;
  }
  .big-cross { width: 160px; height: 160px; max-width: 52vw; display: block; margin: 0 auto 8px; fill: #e00000; filter: drop-shadow(0 0 8px rgba(224,0,0,.35)); }
  .not-food-title { color: var(--danger-title); font-size: calc(30px * var(--fs)); font-weight: 800; line-height: 1.2; }
  .not-food-title .zh { display: block; font-size: calc(24px * var(--fs)); }
  .not-food-reason { color: var(--danger-text); font-size: calc(18px * var(--fs)); margin-top: 12px; }
  .not-food-reason .zh { display: block; color: var(--danger-text); font-size: calc(17px * var(--fs)); }
  .mods { margin: 0 0 12px 24px; }
  .mods li { font-size: calc(18px * var(--fs)); margin: 10px 0; color: var(--text); }
  .mods li .zh { display: block; color: var(--muted); font-size: calc(17px * var(--fs)); }
  .chips { display: flex; flex-wrap: wrap; gap: 8px; margin: 6px 0 10px; }
  .chip { display: inline-flex; align-items: center; gap: 4px; padding: 6px 10px; border-radius: 999px; font-size: calc(16px * var(--fs)); font-weight: 700; line-height: 1.2; }
  .chip.cat { background: var(--tint); color: var(--accent-dark); border: 1px solid var(--tint-border); }
  .chip.hazard { background: var(--orange-bg); color: var(--orange); }
  .chip.allergen { background: var(--red-bg); color: var(--red); }
  .chip-label { font-size: calc(15px * var(--fs)); font-weight: 800; color: var(--muted); align-self: center; }
  .chip-row { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; align-items: center; }
  .item-cat { display: inline-block; margin-top: 4px; font-size: calc(16px * var(--fs)); font-weight: 700; color: var(--accent-dark); }
  .allergy-warning { background: var(--warn-bg); border: 3px solid var(--warn-border); border-radius: 14px; padding: 12px 14px; margin: 10px 0 14px; color: var(--warn-text); }
  .allergy-warning strong { display: block; font-size: calc(18px * var(--fs)); margin-bottom: 4px; }
  .allergy-warning .chips { margin: 6px 0; }
  .allergy-sub { font-size: calc(15px * var(--fs)); font-weight: 600; color: var(--warn-text); }
  .health-notes { margin: 4px 0 14px; }
  .health-item { border: 2px solid var(--pink-border); border-radius: 14px; padding: 12px 14px; margin-bottom: 10px; background: var(--item-bg); }
  .health-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 4px; }
  .health-cond { font-size: calc(18px * var(--fs)); font-weight: 800; color: var(--text); }
  .health-reason { font-size: calc(17px * var(--fs)); color: var(--text); }
  .health-reason .zh { display: block; color: var(--muted); font-size: calc(16px * var(--fs)); }
  .health-disclaimer { font-size: calc(15px * var(--fs)); color: var(--red); font-weight: 700; margin-top: 6px; line-height: 1.45; }
  .allergy-note { font-size: calc(16px * var(--fs)); color: var(--red); margin-top: 8px; font-weight: 700; line-height: 1.45; }
  .tts-row { display: flex; gap: 10px; margin: 14px 0 4px; }
  .tts-row .btn { flex: 1; min-height: calc(58px * var(--fs)); font-size: calc(18px * var(--fs)); }

  /* ---------- Settings ---------- */
  .header-actions { margin-left: auto; display: flex; gap: 8px; align-items: flex-start; }
  .settings-btn {
    background: rgba(255,255,255,.22); border: none; color: #fff;
    font-size: 22px; width: auto; min-width: 48px; height: auto;
    border-radius: 12px; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    flex-direction: column; gap: 3px; padding: 5px 7px 6px;
  }
  .settings-btn:active { transform: scale(.94); }
  .settings-btn .gear { font-size: 22px; line-height: 1; }
  .settings-btn .btn-label { font-size: 11px; font-weight: 700; line-height: 1; opacity: .95; white-space: nowrap; }
  .settings-panel {
    position: fixed; inset: 0; z-index: 60; display: none;
    background: rgba(0,0,0,.5); align-items: flex-end; justify-content: center;
  }
  .settings-panel.show { display: flex; }
  .settings-sheet {
    background: var(--card); width: 100%; max-width: 640px;
    border-radius: 20px 20px 0 0; padding: 18px 16px;
    padding-bottom: calc(18px + env(safe-area-inset-bottom));
    max-height: 92vh; max-height: 92dvh; overflow-y: auto;
    border-top: 4px solid var(--accent);
  }
  .sheet-head { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
  .sheet-head h2 { font-size: calc(22px * var(--fs)); font-weight: 800; }
  .sheet-close {
    margin-left: auto; background: var(--tint); color: var(--accent-dark);
    border: 2px solid var(--tint-border); font-size: calc(18px * var(--fs));
    width: calc(46px * var(--fs)); height: calc(46px * var(--fs));
    border-radius: 12px; cursor: pointer; font-weight: 800;
  }
  .sheet-sub { font-size: calc(15px * var(--fs)); color: var(--muted); margin-bottom: 12px; }
  .set-group { margin-bottom: 18px; }
  .set-label {
    font-size: calc(17px * var(--fs)); font-weight: 800; color: var(--accent-dark);
    margin-bottom: 8px; display: flex; align-items: center; gap: 8px;
  }
  .set-label .tag {
    font-size: calc(13px * var(--fs)); font-weight: 700; color: var(--muted);
    background: var(--tint); border: 1px solid var(--tint-border);
    border-radius: 999px; padding: 2px 8px;
  }
  .set-opts { display: flex; flex-wrap: wrap; gap: 8px; }
  .set-opts button {
    flex: 1 1 auto; min-width: 96px; min-height: calc(52px * var(--fs));
    padding: 10px 12px; border-radius: 12px; border: 2px solid var(--tint-border);
    background: var(--card); color: var(--text); cursor: pointer;
    font-size: calc(16px * var(--fs)); font-weight: 700; font-family: inherit;
    line-height: 1.25;
  }
  .set-opts button small { display: block; font-weight: 400; font-size: calc(13px * var(--fs)); color: var(--muted); margin-top: 2px; }
  .set-opts button[aria-pressed="true"] {
    border-color: var(--accent); background: var(--tint); color: var(--accent-dark);
    box-shadow: inset 0 0 0 2px rgba(216,27,96,.12);
  }
  .set-opts.conditions button[aria-pressed="true"] { background: var(--accent); color: #fff; border-color: var(--accent); }
  .set-opts.conditions button[aria-pressed="true"] small { color: rgba(255,255,255,.9); }
  .set-note { font-size: calc(14px * var(--fs)); color: var(--muted); margin-top: 8px; }
  .set-more {
    margin-top: 10px; width: 100%; min-height: calc(48px * var(--fs));
    border-radius: 12px; border: 2px dashed var(--tint-border);
    background: var(--card); color: var(--accent-dark);
    font-size: calc(16px * var(--fs)); font-weight: 700; font-family: inherit;
    cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  }
  .set-more:active { transform: scale(.99); }
  .set-more .chev { transition: transform .18s ease; }
  .set-more[aria-expanded="true"] .chev { transform: rotate(180deg); }
  .set-more[aria-expanded="true"] { border-style: solid; background: var(--tint); }
  .set-more.has-selection { border-color: var(--accent); color: var(--accent-dark); }
  .set-more.has-selection::before { content: "\2022"; color: var(--accent); font-weight: 900; }
  .set-extra { margin-top: 8px; }
  .set-extra.hidden { display: none; }
  .sheet-foot { display: flex; gap: 10px; margin-top: 4px; }
  .sheet-foot .btn { flex: 1; font-size: calc(17px * var(--fs)); min-height: calc(56px * var(--fs)); }
  .profile-saved {
    display: none; margin-top: 10px; padding: 10px 12px; border-radius: 12px;
    background: var(--green-bg); color: var(--green); font-weight: 700;
    font-size: calc(15px * var(--fs));
  }
  .profile-saved.show { display: block; }
  .hidden { display: none !important; }
  #previewImg { width: 100%; border-radius: 14px; display: block; margin-bottom: 12px; border: 2px solid var(--pink-border); }
  /* Right-to-left languages (Arabic, Hebrew, Persian, Urdu, Yiddish). */
  html[dir="rtl"] body { direction: rtl; }
  html[dir="rtl"] .hint { border-left: none; border-right: 5px solid var(--accent); }
  html[dir="rtl"] .badge { margin-left: 0; margin-right: 6px; }
  html[dir="rtl"] .header-actions { margin-left: 0; margin-right: auto; }
  html[dir="rtl"] .sheet-close { margin-left: 0; margin-right: auto; }
</style>
</head>
<body>
<header>
  <div class="brand">
    <img src="/icon-192.png?v=2" alt="" class="app-icon">
    <div>
      <h1 data-i18n="app.title">Food Texture Checker</h1>
      <div class="sub" data-i18n="app.sub">食物質地檢查 · 吞嚥友好</div>
    </div>
    <div class="header-actions">
      <button class="fs-btn" id="btnFullscreen" type="button" title="Full screen" aria-label="Full screen">⛶</button>
      <button class="settings-btn" id="btnSettings" type="button" title="Settings" aria-label="Settings">
        <span class="gear" aria-hidden="true">⚙</span>
        <span class="btn-label" id="settingsLabel">Settings</span>
      </button>
    </div>
  </div>
</header>
<script>
// Set the word under the gear from the saved language, before the first paint.
(function () {
  var LABEL = { zh: '設定', yue: '設定', zh_cn: '设置' };
  var p = {};
  try { p = JSON.parse(localStorage.getItem('fc_profile_v1') || '{}') || {}; } catch (e) { p = {}; }
  if (!p || !Object.keys(p).length) {
    try {
      var m = document.cookie.match(/(?:^|;\s*)fc_profile=([^;]*)/);
      if (m && m[1]) p = JSON.parse(decodeURIComponent(m[1])) || {};
    } catch (e) { p = {}; }
  }
  var word = LABEL[p.language] || 'Settings';
  var el = document.getElementById('settingsLabel');
  if (el) el.textContent = word;
  var btn = document.getElementById('btnSettings');
  if (btn) { btn.setAttribute('title', word); btn.setAttribute('aria-label', word); }
  if ({ ar: 1, fa: 1, ur: 1, he: 1, yi: 1 }[p.language]) {
    document.documentElement.setAttribute('dir', 'rtl');
  }
})();
</script>
<main>
  <!-- Diet level selector -->
  <div class="card">
    <div class="label" data-i18n="diet.label">Diet level 飲食級別 (IDDSI)</div>
    <div class="iddsi" id="iddsi">
      <button data-level="liquid"><span data-i18n="level.liquid">Liquid 流質</span><small data-i18n="level.liquid.sub">Drinks only</small></button>
      <button data-level="puree"><span data-i18n="level.puree">Puréed 糊狀</span><small data-i18n="level.puree.sub">Very smooth</small></button>
      <button data-level="minced"><span data-i18n="level.minced">Minced &amp; Moist 碎濕狀</span><small data-i18n="level.minced.sub">Soft lumps</small></button>
      <button data-level="soft"><span data-i18n="level.soft">Soft 軟食</span><small data-i18n="level.soft.sub">Soft solids</small></button>
    </div>
  </div>

  <!-- Camera -->
  <div class="camera-box off hidden" id="camBox" role="button" tabindex="0" aria-label="Tap to capture a food photo">
    <video id="video" playsinline autoplay muted class="hidden"></video>
    <div class="tap-hint" data-i18n="camera.tap">📸 Tap to capture 點按拍攝</div>
    <div id="camPlaceholder">
      <svg class="cam-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3 7.5 5H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3.5L15 3H9zm3 5a5 5 0 1 1 0 10 5 5 0 0 1 0-10zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/></svg>
      <div><span data-i18n="camera.closed">Camera is closed / 相機已關閉</span></div>
    </div>
  </div>
  <div class="btn-row">
    <button class="btn btn-secondary" id="btnCam" data-i18n="btn.openCam">📷 Open camera 開啟相機</button>
    <button class="btn btn-primary" id="btnCapture" disabled data-i18n="btn.check">📸 Check food 檢查食物</button>
  </div>
  <div class="hint" data-i18n="tip">Tip: make sure all dishes are in frame with a plain background. Tap the camera or press Check food to capture. 提示：請將整盤食物放入畫面，背景簡潔更容易識別。點按相機或按「檢查食物」即可拍攝。</div>
  <div class="status" id="status"></div>

  <!-- Result -->
  <div class="card hidden" id="resultCard" aria-live="polite">
    <img id="previewImg" alt="captured food">
    <div id="result"></div>
    <div class="tts-row hidden" id="ttsRow">
      <button class="btn btn-secondary" id="btnRead" type="button" data-i18n="tts.read">🔊 Read result 朗讀結果</button>
      <button class="btn btn-secondary" id="btnStopRead" type="button" data-i18n="tts.stop">⏹ Stop 停止</button>
    </div>
    <div class="disclaimer" data-i18n="disclaimer">⚠️ This is a visual aid only. It cannot confirm ingredients or allergens. Always follow your doctor or dietitian/nutritionist. 此結果僅供參考，無法確認食材或致敏物。請遵從醫生或營養師的建議。</div>
    <div class="allergy-note" data-i18n="allergy.note">If you have a food allergy, confirm ingredients with the kitchen and follow your allergy plan. 如有食物過敏，請向廚房確認食材並遵從過敏處理計劃。</div>
  </div>
</main>

<!-- Settings sheet -->
<div class="settings-panel" id="settingsPanel" role="dialog" aria-modal="true" aria-labelledby="settingsTitle">
  <div class="settings-sheet" id="settingsSheet">
    <div class="sheet-head">
      <h2 id="settingsTitle" data-i18n="settings.title">Settings 設定</h2>
      <button class="sheet-close" id="btnCloseSettings" type="button" aria-label="Close" title="Close">✕</button>
    </div>
    <div class="sheet-sub" data-i18n="settings.sub">Saved on this device, applied instantly. 即時生效，並儲存於此裝置。</div>

    <div class="set-group">
      <div class="set-label" data-i18n="settings.language">App language 應用程式語言</div>
      <div class="set-note" data-i18n="settings.language.note">The whole app, the analysis result and the spoken answer use this language.</div>
      <div class="set-opts" id="optLanguage"></div>
      <button class="set-more" type="button" data-target="optLanguage" aria-expanded="false" aria-controls="optLanguageExtra">More 更多 <span class="chev">▾</span></button>
      <div class="set-opts set-extra hidden" id="optLanguageExtra"></div>
    </div>

    <div class="set-group">
      <div class="set-label"><span data-i18n="settings.conditions">Long-term conditions 長期病患</span> <span class="tag" id="condTag"></span></div>
      <div class="set-opts" id="optConditions"></div>
      <button class="set-more" type="button" data-target="optConditions" aria-expanded="false" aria-controls="optConditionsExtra">More 更多 <span class="chev">▾</span></button>
      <div class="set-opts set-extra hidden" id="optConditionsExtra"></div>
      <div class="set-note" data-i18n="settings.conditions.note">The analysis focuses on these. 分析會特別留意以上病患。</div>
    </div>

    <div class="set-group">
      <div class="set-label" data-i18n="settings.size">Text size 字體大小</div>
      <div class="set-opts" id="optSize"></div>
      <button class="set-more" type="button" data-target="optSize" aria-expanded="false" aria-controls="optSizeExtra">More 更多 <span class="chev">▾</span></button>
      <div class="set-opts set-extra hidden" id="optSizeExtra"></div>
    </div>

    <div class="set-group">
      <div class="set-label" data-i18n="settings.theme">Theme 主題</div>
      <div class="set-opts" id="optTheme"></div>
      <button class="set-more" type="button" data-target="optTheme" aria-expanded="false" aria-controls="optThemeExtra">More 更多 <span class="chev">▾</span></button>
      <div class="set-opts set-extra hidden" id="optThemeExtra"></div>
    </div>

    <div class="set-group">
      <div class="set-label" data-i18n="settings.color">Text colour 字體顏色</div>
      <div class="set-opts" id="optColor"></div>
      <button class="set-more" type="button" data-target="optColor" aria-expanded="false" aria-controls="optColorExtra">More 更多 <span class="chev">▾</span></button>
      <div class="set-opts set-extra hidden" id="optColorExtra"></div>
    </div>

    <div class="set-group">
      <div class="set-label" data-i18n="settings.font">Font type 字型</div>
      <div class="set-opts" id="optFont"></div>
      <button class="set-more" type="button" data-target="optFont" aria-expanded="false" aria-controls="optFontExtra">More 更多 <span class="chev">▾</span></button>
      <div class="set-opts set-extra hidden" id="optFontExtra"></div>
    </div>

    <div class="set-group">
      <div class="set-label" data-i18n="settings.speak">Speak results 語音朗讀</div>
      <div class="set-opts" id="optSpeak"></div>
      <button class="set-more" type="button" data-target="optSpeak" aria-expanded="false" aria-controls="optSpeakExtra">More 更多 <span class="chev">▾</span></button>
      <div class="set-opts set-extra hidden" id="optSpeakExtra"></div>
    </div>

    <div class="sheet-foot">
      <button class="btn btn-secondary" id="btnResetSettings" type="button" data-i18n="settings.reset">Reset 重設</button>
      <button class="btn btn-primary" id="btnDoneSettings" type="button" data-i18n="settings.done">Done 完成</button>
    </div>
    <div class="profile-saved" id="profileSaved" data-i18n="settings.saved">Saved 已儲存</div>
  </div>
</div>

<script src="/ui-lang.js?v=1"></script>
<script src="/lang.js?v=1"></script>
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

  // ---------- User profile (saved in localStorage) ----------
  var PROFILE_KEY = 'fc_profile_v1';
  var SIZE_SCALE = { xs: 0.78, s: 0.88, m: 1, l: 1.16, xl: 1.32, xxl: 1.5 };
  var PROFILE_DEFAULTS = {
    language: 'both',
    conditions: [],
    size: 'm',
    theme: 'pink',
    color: 'default',
    font: 'system',
    speak: 'on'
  };

  // Option tables. The first few entries of each group are shown directly;
  // the rest live behind the "More 更多" button.
  var THEMES = [
    { v: 'pink', l: 'Pink', s: '粉紅' },
    { v: 'rose', l: 'Rose', s: '玫瑰' },
    { v: 'ocean', l: 'Blue', s: '藍色' },
    { v: 'forest', l: 'Green', s: '綠色' },
    { v: 'grape', l: 'Purple', s: '紫色' },
    { v: 'sunset', l: 'Sunset', s: '橙紅' },
    { v: 'teal', l: 'Teal', s: '青綠' },
    { v: 'slate', l: 'Slate', s: '灰藍' },
    { v: 'sepia', l: 'Sepia', s: '米色' },
    { v: 'crimson', l: 'Crimson', s: '深紅' },
    { v: 'navy', l: 'Navy', s: '深藍' },
    { v: 'dark', l: 'Dark', s: '深色' },
    { v: 'midnight', l: 'Midnight', s: '午夜藍' },
    { v: 'contrast', l: 'High contrast', s: '高對比' }
  ];
  var COLORS = [
    { v: 'default', l: 'Default', s: '預設' },
    { v: 'dark', l: 'Black', s: '黑色' },
    { v: 'soft', l: 'Grey', s: '灰色' },
    { v: 'warm', l: 'Brown', s: '啡色' },
    { v: 'navy', l: 'Navy', s: '深藍' },
    { v: 'green', l: 'Green', s: '深綠' },
    { v: 'maroon', l: 'Maroon', s: '酒紅' }
  ];
  var SIZES = [
    { v: 'xs', l: 'Extra small', s: '特小' },
    { v: 's', l: 'Small', s: '小' },
    { v: 'm', l: 'Normal', s: '中' },
    { v: 'l', l: 'Large', s: '大' },
    { v: 'xl', l: 'Extra large', s: '特大' },
    { v: 'xxl', l: 'Largest', s: '最大' }
  ];
  var FONTS = [
    { v: 'system', l: 'System', s: '系統預設' },
    { v: 'rounded', l: 'Rounded', s: '圓體 Nunito' },
    { v: 'serif', l: 'Serif', s: '襯線' },
    { v: 'mono', l: 'Mono', s: '等寬' },
    { v: 'slab', l: 'Slab', s: '粗襯線' },
    { v: 'humanist', l: 'Humanist', s: '人文' }
  ];
  var SPEAKS = [
    { v: 'on', l: 'On', s: '開' },
    { v: 'off', l: 'Off', s: '關' },
    { v: 'en', l: 'English only', s: '只英文' },
    { v: 'zh', l: 'Chinese only', s: '只中文' }
  ];
  var CONDITIONS = [
    { v: 'diabetes', l: 'Diabetes', s: '糖尿病' },
    { v: 'heart_disease', l: 'Heart disease', s: '心臟病' },
    { v: 'stroke', l: 'Stroke', s: '中風' },
    { v: 'kidney_disease', l: 'Kidney disease', s: '腎病' },
    { v: 'liver_disease', l: 'Liver disease', s: '肝病' },
    { v: 'hypertension', l: 'High blood pressure', s: '高血壓' },
    { v: 'high_cholesterol', l: 'High cholesterol', s: '高膽固醇' },
    { v: 'gout', l: 'Gout', s: '痛風' }
  ];

  // A built-in fallback so the app still works if lang.js cannot be loaded.
  var FALLBACK_LANGUAGES = [
    { v: 'both', l: 'Both 兩者', s: 'English + 中文', f: 'both', tts: 'en-US' },
    { v: 'en', l: 'English', s: '英文', f: 'en', tts: 'en-US' },
    { v: 'zh', l: '繁體中文', s: '繁體中文（香港）', f: 'zh', tts: 'zh-HK' },
    { v: 'yue', l: '廣東話', s: '粵語（書面繁體）', f: 'zh', tts: 'zh-HK' },
    { v: 'zh_cn', l: '简体中文', s: '簡體中文', f: 'zh', tts: 'zh-CN' },
    { v: 'es', l: 'Español', s: '西班牙文', f: 'en', tts: 'es-ES' },
    { v: 'fr', l: 'Français', s: '法文', f: 'en', tts: 'fr-FR' },
    { v: 'de', l: 'Deutsch', s: '德文', f: 'en', tts: 'de-DE' },
    { v: 'ja', l: '日本語', s: '日文', f: 'en', tts: 'ja-JP' },
    { v: 'ko', l: '한국어', s: '韓文', f: 'en', tts: 'ko-KR' }
  ];
  var LANGUAGE_TABLE = (typeof FC_LANGUAGES !== 'undefined' && FC_LANGUAGES && FC_LANGUAGES.length)
    ? FC_LANGUAGES
    : FALLBACK_LANGUAGES;
  if (typeof FC_TTS_LOCALE === 'undefined') {
    FC_TTS_LOCALE = {};
    LANGUAGE_TABLE.forEach(function (l) { FC_TTS_LOCALE[l.v] = l.tts; });
  }
  if (typeof FC_LANG_FIELD === 'undefined') {
    FC_LANG_FIELD = {};
    LANGUAGE_TABLE.forEach(function (l) { FC_LANG_FIELD[l.v] = l.f; });
  }

  // ---------- UI text (English / Traditional Chinese) ----------
  var UI_I18N = {
    'app.title': { en: 'Food Texture Checker', zh: '食物質地檢查器' },
    'app.sub': { en: 'Food texture check · Swallow friendly', zh: '食物質地檢查 · 吞嚥友好' },
    'btn.fullscreen': { en: 'Full screen', zh: '全屏' },
    'btn.settings': { en: 'Settings', zh: '設定' },
    'diet.label': { en: 'Diet level (IDDSI)', zh: '飲食級別 (IDDSI)' },
    'level.liquid': { en: 'Liquid', zh: '流質' },
    'level.liquid.sub': { en: 'Drinks only', zh: '只可飲用' },
    'level.puree': { en: 'Puréed', zh: '糊狀' },
    'level.puree.sub': { en: 'Very smooth', zh: '非常幼滑' },
    'level.minced': { en: 'Minced & Moist', zh: '碎濕狀' },
    'level.minced.sub': { en: 'Soft lumps', zh: '軟身小塊' },
    'level.soft': { en: 'Soft', zh: '軟食' },
    'level.soft.sub': { en: 'Soft solids', zh: '軟質食物' },
    'camera.tap': { en: '📸 Tap to capture', zh: '📸 點按拍攝' },
    'camera.closed': { en: 'Camera is closed', zh: '相機已關閉' },
    'camera.aria': { en: 'Tap to capture a food photo', zh: '點按拍攝食物照片' },
    'btn.openCam': { en: '📷 Open camera', zh: '📷 開啟相機' },
    'btn.closeCam': { en: '⏹ Close camera', zh: '⏹ 關閉相機' },
    'btn.check': { en: '📸 Check food', zh: '📸 檢查食物' },
    'btn.analyzing': { en: 'Analyzing…', zh: '分析中…' },
    'tip': {
      en: 'Tip: make sure all dishes are in frame with a plain background. Tap the camera or press Check food to capture.',
      zh: '提示：請將整盤食物放入畫面，背景簡潔更容易識別。點按相機或按「檢查食物」即可拍攝。'
    },
    'result.foodTypes': { en: 'Food types', zh: '食物類型' },
    'result.allergens': { en: 'Possible allergens', zh: '可能致敏物' },
    'result.allergenNote': { en: 'Photo estimate only. Confirm with the kitchen.', zh: '僅供參考，請向廚房確認。' },
    'result.health': { en: 'Long-term health notes', zh: '長期病患提示' },
    'result.mods': { en: 'Ways to make it safer', zh: '安全調整建議' },
    'result.ingredients': { en: 'Ingredients', zh: '食材明細' },
    'result.texture': { en: 'Texture', zh: '質地' },
    'result.hazards': { en: 'Hazards', zh: '風險' },
    'result.allergensShort': { en: 'Allergens', zh: '致敏物' },
    'result.risk': { en: 'Risk', zh: '風險' },
    'result.dontEat': { en: 'Do not eat', zh: '請勿食用' },
    'result.notFood': {
      en: 'This does not appear to be food, or it may not be safe to eat.',
      zh: '這似乎不是食物，或可能不安全，請勿食用。'
    },
    'disclaimer': {
      en: '⚠️ This is a visual aid only. It cannot confirm ingredients or allergens. Always follow your doctor or dietitian/nutritionist.',
      zh: '⚠️ 此結果僅供參考，無法確認食材或致敏物。請遵從醫生或營養師的建議。'
    },
    'allergy.note': {
      en: 'If you have a food allergy, confirm ingredients with the kitchen and follow your allergy plan.',
      zh: '如有食物過敏，請向廚房確認食材並遵從過敏處理計劃。'
    },
    'tts.read': { en: '🔊 Read result', zh: '🔊 朗讀結果' },
    'tts.stop': { en: '⏹ Stop', zh: '⏹ 停止' },
    'tts.reading': { en: '🔊 Reading…', zh: '🔊 朗讀中…' },
    'tts.again': { en: '🔊 Read again', zh: '🔊 再朗讀' },
    'status.analyzing': { en: 'Analyzing…', zh: '正在分析…' },
    'status.done': { en: 'Done.', zh: '分析完成。' },
    'status.failed': { en: 'Analysis failed', zh: '分析失敗' },
    'status.timeout': { en: 'Request timed out.', zh: '請求逾時。' },
    'status.camUnsupported': { en: 'Camera not supported in this browser.', zh: '此瀏覽器不支援相機。' },
    'status.camError': { en: 'Camera error', zh: '相機錯誤' },
    'status.camNotReady': { en: 'Camera not ready.', zh: '相機未就緒。' },
    'status.needKey': { en: 'Please enter the backend API key in Settings.', zh: '請在設定中填寫後端密鑰。' },
    'status.tokenFailed': { en: 'Could not get a session token. Please reload the page.', zh: '無法取得工作階段權杖，請重新載入頁面。' },
    'status.iphoneFullscreen': {
      en: 'On iPhone, tap Share then "Add to Home Screen" for full screen.',
      zh: '在 iPhone 上請按「分享」→「加入主畫面」以全屏顯示。'
    },
    'settings.title': { en: 'Settings', zh: '設定' },
    'settings.sub': { en: 'Saved on this device, applied instantly.', zh: '即時生效，並儲存於此裝置。' },
    'settings.close': { en: 'Close', zh: '關閉' },
    'settings.language': { en: 'App language', zh: '應用程式語言' },
    'settings.language.note': {
      en: 'The whole app, the analysis result and the spoken answer use this language.',
      zh: '整個應用程式、分析結果及語音朗讀都會使用此語言。'
    },
    'settings.conditions': { en: 'Long-term conditions', zh: '長期病患' },
    'settings.conditions.note': { en: 'The analysis focuses on these.', zh: '分析會特別留意以上病患。' },
    'settings.conditions.none': { en: 'none selected', zh: '未選' },
    'settings.conditions.count': { en: 'selected', zh: '已選' },
    'settings.size': { en: 'Text size', zh: '字體大小' },
    'settings.theme': { en: 'Theme', zh: '主題' },
    'settings.color': { en: 'Text colour', zh: '字體顏色' },
    'settings.font': { en: 'Font type', zh: '字型' },
    'settings.speak': { en: 'Speak results', zh: '語音朗讀' },
    'settings.more': { en: 'More', zh: '更多' },
    'settings.less': { en: 'Less', zh: '收起' },
    'settings.reset': { en: 'Reset', zh: '重設' },
    'settings.done': { en: 'Done', zh: '完成' },
    'settings.saved': { en: 'Saved', zh: '已儲存' },
    'speak.ingredients': { en: 'Ingredients.', zh: '食材。' },
    'speak.foodTypes': { en: 'Food types.', zh: '食物類型。' },
    'speak.allergens': { en: 'Possible allergens.', zh: '可能致敏物。' },
    'speak.hazards': { en: 'Possible hazards.', zh: '可能風險。' },
    'speak.health': { en: 'Long-term health notes.', zh: '長期病患提示。' },
    'speak.mods': { en: 'Ways to make it safer.', zh: '安全調整建議。' },
    'speak.dontEat': { en: 'Warning. Do not eat.', zh: '警告。請勿食用。' },
    'speak.resultFor': { en: 'Result for', zh: '結果：' }
  };

  // 'both' shows English and Traditional Chinese side by side. Any other
  // value is a real language code from ui-lang.js.
  function uiMode() {
    return profile.language === 'both' ? 'both' : profile.language;
  }

  function uiDict() {
    var l = profile.language;
    if (l === 'both') return null;
    if (typeof FC_UI !== 'undefined' && FC_UI && FC_UI[l]) return FC_UI[l];
    return null;
  }

  function uiTranslation(key) {
    var d = uiDict();
    return d && typeof d[key] === 'string' ? d[key] : '';
  }

  function t(key) {
    var translated = uiTranslation(key);
    if (translated) return translated;
    var entry = UI_I18N[key];
    if (!entry) return key;
    if (profile.language === 'both') return entry.en + ' / ' + entry.zh;
    if (profile.language === 'zh' || profile.language === 'yue' || profile.language === 'zh_cn') {
      return entry.zh;
    }
    return entry.en;
  }

  // A label from one of the option tables, rendered in the app language.
  function mapLabel(map, key, ns) {
    var translated = ns ? uiTranslation('dyn.' + ns + '.' + key) : '';
    if (translated) return esc(translated);
    var e = map[key] || labelFor(map, key);
    if (profile.language === 'both') return esc(e.en) + (e.zh ? ' / ' + esc(e.zh) : '');
    if (profile.language === 'zh' || profile.language === 'yue' || profile.language === 'zh_cn') {
      return esc(e.zh || e.en);
    }
    return esc(e.en);
  }

  var LANGUAGES = LANGUAGE_TABLE.map(function (l) { return l.v; });

  function updateHeaderLabels() {
    var label = t('btn.settings');
    var el = $('settingsLabel');
    if (el) el.textContent = label;
    var btn = $('btnSettings');
    if (btn) {
      btn.setAttribute('title', label);
      btn.setAttribute('aria-label', label);
    }
  }

  var profile = loadProfile();

  function loadProfile() {
    var saved = {};
    try {
      saved = JSON.parse(localStorage.getItem(PROFILE_KEY) || '{}') || {};
    } catch (e) { saved = {}; }

    // Fallback for browsers that block localStorage (private mode, strict
    // settings): a plain cookie keeps the profile across refreshes. This is a
    // separate try block because localStorage.getItem may throw.
    if (!saved || !Object.keys(saved).length) {
      try {
        var match = document.cookie.match(/(?:^|;\s*)fc_profile=([^;]*)/);
        if (match && match[1]) saved = JSON.parse(decodeURIComponent(match[1])) || {};
      } catch (e) { /* ignore */ }
    }

    var merged = Object.assign({}, PROFILE_DEFAULTS, saved);
    // Older profiles stored a separate accent colour; fold it into the theme.
    if (merged.accent && merged.accent !== 'pink') {
      var accentToTheme = {
        blue: 'ocean', green: 'forest', purple: 'grape', orange: 'sunset',
        teal: 'teal', red: 'crimson', navy: 'navy', brown: 'sepia'
      };
      if (accentToTheme[merged.accent]) merged.theme = accentToTheme[merged.accent];
    }
    delete merged.accent;
    if (!LANGUAGES.includes(merged.language)) merged.language = PROFILE_DEFAULTS.language;
    if (!SIZE_SCALE[merged.size]) merged.size = PROFILE_DEFAULTS.size;
    if (!optionValues(THEMES).includes(merged.theme)) merged.theme = PROFILE_DEFAULTS.theme;
    if (!optionValues(COLORS).includes(merged.color)) merged.color = PROFILE_DEFAULTS.color;
    if (!optionValues(FONTS).includes(merged.font)) merged.font = PROFILE_DEFAULTS.font;
    if (!['on', 'off', 'en', 'zh'].includes(merged.speak)) merged.speak = PROFILE_DEFAULTS.speak;
    merged.conditions = Array.isArray(merged.conditions)
      ? merged.conditions.filter(function (c) { return typeof c === 'string'; })
      : [];
    return merged;
  }

  // The option tables hold objects; this returns their plain values.
  function optionValues(table) {
    return table.map(function (o) { return o.v; });
  }


  function saveProfile() {
    var raw = JSON.stringify(profile);
    try {
      localStorage.setItem(PROFILE_KEY, raw);
    } catch (e) { /* storage may be unavailable in private mode */ }
    // Always mirror to a cookie so a refresh keeps the settings even when
    // localStorage is unavailable. One year, same-site, path scoped.
    try {
      document.cookie = 'fc_profile=' + encodeURIComponent(raw)
        + '; path=/; max-age=31536000; SameSite=Lax';
    } catch (e) { /* cookies may be blocked */ }
  }

  function applyProfile() {
    var root = document.documentElement;
    root.setAttribute('data-theme', profile.theme);
    root.setAttribute('data-color', profile.color);
    root.setAttribute('data-font', profile.font);
    var htmlLang = profile.language === 'both' || profile.language === 'zh'
      ? 'zh-Hant'
      : profile.language === 'yue' ? 'zh-HK'
      : profile.language === 'zh_cn' ? 'zh-Hans'
      : profile.language.replace('_', '-');
    root.setAttribute('lang', htmlLang);
    var rtl = ['ar', 'fa', 'ur', 'he', 'yi'];
    root.setAttribute('dir', rtl.indexOf(profile.language) >= 0 ? 'rtl' : 'ltr');
    root.style.setProperty('--fs', SIZE_SCALE[profile.size] || 1);

    applyUiLanguage();
    syncAllOptions();
    updateHeaderLabels();

    // Reflect the current state on the option buttons.
    syncOptions('optLanguage', profile.language);
    syncOptions('optSize', profile.size);
    syncOptions('optTheme', profile.theme);
    syncOptions('optColor', profile.color);
    syncOptions('optFont', profile.font);
    syncOptions('optSpeak', profile.speak);
    $('optConditions').querySelectorAll('button').forEach(function (b) {
      b.setAttribute('aria-pressed', profile.conditions.indexOf(b.getAttribute('data-value')) >= 0 ? 'true' : 'false');
    });
    $('optConditionsExtra').querySelectorAll('button').forEach(function (b) {
      b.setAttribute('aria-pressed', profile.conditions.indexOf(b.getAttribute('data-value')) >= 0 ? 'true' : 'false');
    });

    // Show the count of selected conditions.
    var tag = $('condTag');
    if (tag) {
      tag.textContent = profile.conditions.length
        ? profile.conditions.length + ' ' + t('settings.conditions.count')
        : t('settings.conditions.none');
    }

    // If the current value lives in a collapsed group, hint that it is set.
    ['optLanguage', 'optSize', 'optTheme', 'optColor', 'optFont', 'optSpeak'].forEach(function (id) {
      var extra = $(id + 'Extra');
      if (!extra) return;
      var selected = extra.querySelector('button[aria-pressed="true"]');
      var more = document.querySelector('.set-more[data-target="' + id + '"]');
      if (more) {
        more.classList.toggle('has-selection', !!selected);
        more.title = selected ? (selected.getAttribute('data-label') || '') : '';
      }
    });
  }

  function syncOptions(groupId, value) {
    $(groupId).querySelectorAll('button').forEach(function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-value') === value ? 'true' : 'false');
    });
    var extra = $(groupId + 'Extra');
    if (extra) {
      extra.querySelectorAll('button').forEach(function (b) {
        b.setAttribute('aria-pressed', b.getAttribute('data-value') === value ? 'true' : 'false');
      });
    }
  }

  // ---------- Build the option buttons ----------
  function optionLabel(opt, ns) {
    if (profile.language === 'both') {
      return esc(opt.l) + (opt.s ? '<small>' + esc(opt.s) + '</small>' : '');
    }
    // Language names stay in their own language so people can find theirs.
    if (ns === 'LANGUAGE') return esc(opt.l);
    var translated = ns ? uiTranslation('opt.' + ns + '.' + opt.v) : '';
    if (translated) return esc(translated);
    if (profile.language === 'zh' || profile.language === 'yue' || profile.language === 'zh_cn') {
      return esc(opt.s || opt.l);
    }
    return esc(opt.l);
  }

  function makeOptionButton(opt, ns) {
    var b = document.createElement('button');
    b.type = 'button';
    b.setAttribute('data-value', opt.v);
    b.setAttribute('data-label', opt.l);
    b.setAttribute('aria-pressed', 'false');
    b.innerHTML = optionLabel(opt, ns);
    return b;
  }

  function buildGroup(mainId, extraId, options, basicCount, ns) {
    var main = $(mainId);
    var extra = $(extraId);
    main.innerHTML = '';
    extra.innerHTML = '';

    options.forEach(function (opt, i) {
      var button = makeOptionButton(opt, ns);
      if (i < basicCount) {
        main.appendChild(button);
      } else {
        extra.appendChild(button);
      }
    });

    if (!options.length || options.length <= basicCount) {
      var more = document.querySelector('.set-more[data-target="' + mainId + '"]');
      if (more) more.classList.add('hidden');
    }
  }

  function buildSettingsUI() {
    // Languages: the ten most used are shown first, the rest behind "More".
    // "Both 兩者" is the first basic choice, then the common languages.
    var languageOptions = LANGUAGE_TABLE
      .filter(function (l) { return l.v === 'both'; })
      .concat(LANGUAGE_TABLE.filter(function (l) { return l.v !== 'both'; }))
      .map(function (l) { return { v: l.v, l: l.l, s: l.s }; });
    buildGroup('optLanguage', 'optLanguageExtra', languageOptions, 10, 'LANGUAGE');
    buildGroup('optConditions', 'optConditionsExtra', CONDITIONS, 4, 'CONDITIONS');
    buildGroup('optSize', 'optSizeExtra', SIZES, 4, 'SIZES');
    buildGroup('optTheme', 'optThemeExtra', THEMES, 5, 'THEMES');
    buildGroup('optColor', 'optColorExtra', COLORS, 4, 'COLORS');
    buildGroup('optFont', 'optFontExtra', FONTS, 4, 'FONTS');
    buildGroup('optSpeak', 'optSpeakExtra', SPEAKS, 2, 'SPEAKS');
    // Every group keeps a "More" button; hide it only when a group is complete.
    document.querySelectorAll('.set-more').forEach(function (btn) {
      btn.classList.remove('hidden');
    });
  }

  // Swap every tagged word for the chosen language and rebuild the option buttons.
  function applyUiLanguage() {
    var nodes = document.querySelectorAll('[data-i18n]');
    for (var i = 0; i < nodes.length; i++) {
      nodes[i].textContent = t(nodes[i].getAttribute('data-i18n'));
    }
    document.title = 'Food Check · ' + t('app.title');
    var fullscreen = $('btnFullscreen');
    if (fullscreen) {
      fullscreen.setAttribute('title', t('btn.fullscreen'));
      fullscreen.setAttribute('aria-label', t('btn.fullscreen'));
    }
    var camBox2 = $('camBox');
    if (camBox2) camBox2.setAttribute('aria-label', t('camera.aria'));
    var closeBtn = $('btnCloseSettings');
    if (closeBtn) {
      closeBtn.setAttribute('title', t('settings.close'));
      closeBtn.setAttribute('aria-label', t('settings.close'));
    }
    var moreNodes = document.querySelectorAll('.set-more');
    for (var j = 0; j < moreNodes.length; j++) {
      var open = moreNodes[j].getAttribute('aria-expanded') === 'true';
      moreNodes[j].firstChild.nodeValue = t(open ? 'settings.less' : 'settings.more') + ' ';
    }
    buildSettingsUI();
  }

  function syncAllOptions() {
    syncOptions('optLanguage', profile.language);
    syncOptions('optSize', profile.size);
    syncOptions('optTheme', profile.theme);
    syncOptions('optColor', profile.color);
    syncOptions('optFont', profile.font);
    syncOptions('optSpeak', profile.speak);
    ['optConditions', 'optConditionsExtra'].forEach(function (id) {
      $(id).querySelectorAll('button').forEach(function (b) {
        b.setAttribute('aria-pressed',
          profile.conditions.indexOf(b.getAttribute('data-value')) >= 0 ? 'true' : 'false');
      });
    });
  }

  function setProfileValue(key, value) {
    profile[key] = value;
    saveProfile();
    applyProfile();
  }

  // ---------- Settings panel ----------
  var settingsPanel = $('settingsPanel');

  function openSettings() {
    applyProfile();
    settingsPanel.classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  function closeSettings() {
    settingsPanel.classList.remove('show');
    document.body.style.overflow = '';
  }

  $('btnSettings').addEventListener('click', openSettings);
  $('btnCloseSettings').addEventListener('click', closeSettings);
  $('btnDoneSettings').addEventListener('click', function () {
    closeSettings();
    var saved = $('profileSaved');
    saved.classList.add('show');
    setTimeout(function () { saved.classList.remove('show'); }, 2200);
  });
  settingsPanel.addEventListener('click', function (e) {
    if (e.target === settingsPanel) closeSettings();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && settingsPanel.classList.contains('show')) closeSettings();
  });

  $('btnResetSettings').addEventListener('click', function () {
    profile = Object.assign({}, PROFILE_DEFAULTS, { conditions: [] });
    saveProfile();
    applyProfile();
  });

  [
    ['optLanguage', 'language'],
    ['optSize', 'size'],
    ['optTheme', 'theme'],
    ['optColor', 'color'],
    ['optFont', 'font'],
    ['optSpeak', 'speak']
  ].forEach(function (pair) {
    [pair[0], pair[0] + 'Extra'].forEach(function (id) {
      $(id).addEventListener('click', function (e) {
        var b = e.target.closest('button');
        if (!b) return;
        setProfileValue(pair[1], b.getAttribute('data-value'));
      });
    });
  });

  ['optConditions', 'optConditionsExtra'].forEach(function (id) {
    $(id).addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b) return;
      var value = b.getAttribute('data-value');
      var index = profile.conditions.indexOf(value);
      if (index >= 0) profile.conditions.splice(index, 1);
      else profile.conditions.push(value);
      saveProfile();
      applyProfile();
    });
  });

  // The "More 更多" button expands or collapses the extra options.
  document.querySelectorAll('.set-more').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var extra = $(btn.getAttribute('data-target') + 'Extra');
      if (!extra) return;
      var expanded = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      extra.classList.toggle('hidden', expanded);
      btn.firstChild.nodeValue = t(expanded ? 'settings.more' : 'settings.less') + ' ';
    });
  });

  buildSettingsUI();
  applyProfile();

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
      showStatus(t('status.camUnsupported'), true); return;
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
      btnCam.textContent = t('btn.closeCam');
      btnCapture.disabled = false;
      stopSpeaking();
    } catch (err) {
      stopCamera();
      showStatus(t('status.camError') + ': ' + err.message, true);
    }
  }
  function stopCamera() {
    if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    video.srcObject = null;
    video.classList.add('hidden');
    camBox.classList.add('off');
    camBox.classList.add('hidden');
    camPlaceholder.style.display = '';
    btnCam.textContent = t('btn.openCam');
    btnCapture.disabled = true;
    stopSpeaking();
  }
  btnCam.onclick = function () { stream ? stopCamera() : startCamera(); };

  // ---------- Capture (the PHP backend does the final <=1000px resize) ----------
  function captureAndResize() {
    var vw = video.videoWidth, vh = video.videoHeight;
    if (!vw || !vh) { showStatus(t('status.camNotReady'), true); return null; }
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
    btnCapture.textContent = t('btn.analyzing');
    showStatus(t('status.analyzing'));
    try {
      var payload = {
        image: dataUrl,
        level: currentLevel,
        source: 'camera',
        language: profile.language,
        conditions: profile.conditions
      };

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
      var message = err && err.name === 'AbortError' ? t('status.timeout') : err.message;
      showStatus(t('status.failed') + ': ' + message, true);
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
  // Show one or both languages depending on which fields the model filled in.
  function bi(en, zh, cls) {
    var e = en ? esc(en) : '';
    var z = zh ? esc(zh) : '';
    if (e && z) {
      return e + '<br><span class="' + (cls || 'zh') + '">' + z + '</span>';
    }
    if (z && !e) {
      return '<span class="' + (cls || 'zh') + '">' + z + '</span>';
    }
    return e;
  }
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
    $('btnRead').textContent = t('tts.read');
  }

  function speakItems(items) {
    if (!ttsSupported || !items || !items.length) return;
    if (profile.speak === 'off') return;
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
      u.onstart = function () { $('btnRead').textContent = t('tts.reading'); };
      u.onend = function () { if (isLast) $('btnRead').textContent = t('tts.again'); };
      u.onerror = function () { if (isLast) $('btnRead').textContent = t('tts.read'); };
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
        part: 'en',
        text: t('speak.dontEat') + ' ' + (j.warning_en || j.overall_en
          || 'This does not appear to be food, or it may not be safe to eat.')
      });
      out.push({
        lang: 'zh-HK',
        part: 'zh',
        text: t('speak.dontEat') + ' ' + (j.warning_zh || j.overall_zh
          || '這似乎不是食物，或可能不安全。')
      });
      return out;
    }

    var ov = VERDICT[j.overall] || VERDICT.caution;
    out.push({
      lang: 'en-US',
      part: 'en',
      text: (j.dish ? t('speak.resultFor') + ' ' + j.dish + '. ' : '') + mapLabel(VERDICT, j.overall, 'verdict') + '. ' + (j.overall_en || '')
    });
    out.push({
      lang: 'zh-HK',
      part: 'zh',
      text: (j.dish_zh ? j.dish_zh + '。' : '') + mapLabel(VERDICT, j.overall, 'verdict') + '。' + (j.overall_zh || '')
    });

    var items = Array.isArray(j.items) ? j.items : [];
    var itemEn = [], itemZh = [];
    items.forEach(function (it) {
      if (!it) return;
      var v = VERDICT[it.verdict] || VERDICT.caution;
      if (it.name) itemEn.push(it.name + ', ' + v.en);
      if (it.name_zh) itemZh.push(it.name_zh + '，' + v.zh);
    });
    if (itemEn.length) out.push({ lang: 'en-US', part: 'en', text: t('speak.ingredients') + ' ' + itemEn.join('. ') });
    if (itemZh.length) out.push({ lang: 'zh-HK', part: 'zh', text: t('speak.ingredients') + ' ' + itemZh.join('。') });

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
        part: 'en',
        text: t('speak.foodTypes') + ' ' + types.map(function (key) { return labelFor(CATEGORY, key).en; }).join(', ')
      });
      out.push({
        lang: 'zh-HK',
        part: 'zh',
        text: t('speak.foodTypes') + ' ' + types.map(function (key) {
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
        part: 'en',
        text: t('speak.allergens') + ' ' + allergens.map(function (key) { return labelFor(ALLERGEN, key).en; }).join(', ')
      });
      out.push({
        lang: 'zh-HK',
        part: 'zh',
        text: t('speak.allergens') + ' ' + allergens.map(function (key) {
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
        part: 'en',
        text: t('speak.hazards') + ' ' + hazards.map(function (key) { return labelFor(HAZARD, key).en; }).join(', ')
      });
      out.push({
        lang: 'zh-HK',
        part: 'zh',
        text: t('speak.hazards') + ' ' + hazards.map(function (key) {
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
      out.push({ lang: 'en-US', part: 'en', text: t('speak.health') + ' ' + healthEn.join(' ') });
      out.push({ lang: 'zh-HK', part: 'zh', text: t('speak.health') + ' ' + healthZh.join(' ') });
    }

    var mods = Array.isArray(j.modifications) ? j.modifications : [];
    var en = [], zh = [];
    mods.forEach(function (m) {
      if (typeof m === 'string') { if (m) en.push(m); return; }
      if (!m) return;
      if (m.en) en.push(m.en);
      if (m.zh) zh.push(m.zh);
    });
    if (en.length) out.push({ lang: 'en-US', part: 'en', text: t('speak.mods') + ' ' + en.join('. ') });
    if (zh.length) out.push({ lang: 'zh-HK', part: 'zh', text: t('speak.mods') + ' ' + zh.join('。') });

    return out;
  }

  // Keep only the parts the user wants, and use the right voice locale.
  function filterSpeech(items) {
    var mode = profile.speak;
    if (mode === 'off') return [];

    var chosen = FC_LANG_FIELD[profile.language] || 'both';
    var out = items.filter(function (item) {
      if (mode === 'en') return item.part === 'en';
      if (mode === 'zh') return item.part === 'zh';
      // "both" speech: read the language the answer was written in.
      if (chosen === 'en') return item.part === 'en';
      if (chosen === 'zh') return item.part === 'zh';
      return true;
    });

    var locale = FC_TTS_LOCALE[profile.language] || 'en-US';
    out.forEach(function (item) {
      if (chosen === 'both') {
        item.lang = item.part === 'zh' ? 'zh-HK' : locale;
      } else if (chosen === 'zh') {
        item.lang = item.part === 'zh' ? locale : 'zh-HK';
      } else {
        item.lang = item.part === 'en' ? locale : 'zh-HK';
      }
    });

    return out;
  }

  function setupSpeech(j) {
    if (!ttsSupported) {
      $('ttsRow').classList.add('hidden');
      return;
    }

    stopSpeaking();
    lastSpeech = filterSpeech(buildSpeech(j) || []);
    if (lastSpeech && lastSpeech.length) {
      $('ttsRow').classList.remove('hidden');
      clearTimeout(ttsTimer);
      // A short delay lets the result render and scroll into view first.
      if (profile.speak !== 'off') {
        ttsTimer = setTimeout(function () { speakItems(lastSpeech); }, 400);
      }
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
      var reasonEn = j.warning_en || j.overall_en || '';
      var reasonZh = j.warning_zh || j.overall_zh || '';
      var reason = uiMode() === 'both'
        ? (reasonEn ? esc(reasonEn) : '') + (reasonEn && reasonZh ? ' / ' : '') + (reasonZh ? esc(reasonZh) : '')
        : ((profile.language === 'zh' || profile.language === 'yue' || profile.language === 'zh_cn')
          ? (reasonZh ? esc(reasonZh) : esc(reasonEn))
          : esc(reasonEn || reasonZh));
      if (!reason) reason = esc(t('result.notFood'));
      html += '<div class="not-food" role="alert">'
        + '<svg class="big-cross" viewBox="0 0 100 100" aria-hidden="true">'
        + '<path d="M16 6 L50 40 L84 6 L94 16 L60 50 L94 84 L84 94 L50 60 L16 94 L6 84 L40 50 L6 16 Z"/></svg>'
        + '<div class="not-food-title">' + esc(t('result.dontEat')) + '</div>'
        + '<div class="not-food-reason">' + reason + '</div>'
        + '</div>';
    } else if (j && j.items) {
      var ov = VERDICT[j.overall] || VERDICT.caution;
      html += '<div class="verdict v-' + ov.cls + '">' + (ov.cls === 'green' ? '✅' : ov.cls === 'orange' ? '⚠️' : '🚫')
        + ' ' + mapLabel(VERDICT, j.overall, 'verdict') + '</div>'
        + '<div style="margin-bottom:12px">' + bi(j.overall_en, j.overall_zh) + '</div>';

      var types = Array.isArray(j.food_types) ? j.food_types.slice() : [];
      if (!types.length) {
        (j.items || []).forEach(function (it) {
          if (it && it.category && it.category !== 'other' && types.indexOf(it.category) === -1) {
            types.push(it.category);
          }
        });
      }
      if (types.length) {
        html += '<div class="label">' + esc(t('result.foodTypes')) + '</div><div class="chips">';
        types.forEach(function (key) {
          var c = CATEGORY[key] || labelFor(CATEGORY, key);
          html += '<span class="chip cat">' + (c.icon || '🍽️') + ' ' + mapLabel(CATEGORY, key, 'category') + '</span>';
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
        html += '<div class="allergy-warning" role="alert"><strong>🚨 ' + esc(t('result.allergens')) + '</strong><div class="chips">';
        allergens.forEach(function (key) {
          html += '<span class="chip allergen">' + mapLabel(ALLERGEN, key, 'allergen') + '</span>';
        });
        html += '</div><div class="allergy-sub">' + esc(t('result.allergenNote')) + '</div></div>';
      }

      var healthNotes = Array.isArray(j.health_notes) ? j.health_notes : [];
      if (healthNotes.length) {
        html += '<div class="label">' + esc(t('result.health')) + '</div><div class="health-notes">';
        healthNotes.forEach(function (note) {
          var lvl = HEALTH_LEVEL[note.level] || HEALTH_LEVEL.caution;
          html += '<div class="health-item">'
            + '<div class="health-head"><span class="health-cond">'
            + mapLabel(CONDITION, note.condition, 'condition') + '</span>'
            + '<span class="badge b-' + lvl.cls + '">' + mapLabel(HEALTH_LEVEL, note.level, 'health_level') + '</span></div>'
            + '<div class="health-reason">' + bi(note.reason_en, note.reason_zh) + '</div>'
            + '</div>';
        });
        html += '</div><div class="health-disclaimer">General information only, not medical advice. Confirm with your doctor or dietitian. 僅供一般參考，並非醫療建議。請向醫生或營養師確認。</div>';
      }

      var mods = Array.isArray(j.modifications) ? j.modifications : [];
      if (mods.length) {
        html += '<div class="label">' + esc(t('result.mods')) + '</div><ul class="mods">';
        mods.forEach(function (m) {
          if (typeof m === 'string') {
            if (m) html += '<li>' + esc(m) + '</li>';
            return;
          }
          if (!m) return;
          var en = m.en || m.action_en || '';
          var zh = m.zh || m.action_zh || '';
          if (!en && !zh) return;
          html += '<li>' + bi(en, zh) + '</li>';
        });
        html += '</ul>';
      }

      html += '<div class="label">' + esc(t('result.ingredients')) + '</div>';
      (j.items || []).forEach(function (it) {
        var v = VERDICT[it.verdict] || VERDICT.caution;
        var cat = CATEGORY[it.category] || labelFor(CATEGORY, it.category);

        var hazardHtml = '';
        var hazards = Array.isArray(it.hazards) ? it.hazards : [];
        if (hazards.length) {
          hazardHtml = '<div class="chip-row"><span class="chip-label">'
            + esc(t('result.hazards')) + '</span>';
          hazards.forEach(function (key) {
            hazardHtml += '<span class="chip hazard">' + mapLabel(HAZARD, key, 'hazard') + '</span>';
          });
          hazardHtml += '</div>';
        }

        var allergenHtml = '';
        var itemAllergens = Array.isArray(it.allergens) ? it.allergens : [];
        if (itemAllergens.length) {
          allergenHtml = '<div class="chip-row"><span class="chip-label">'
            + esc(t('result.allergensShort')) + '</span>';
          itemAllergens.forEach(function (key) {
            allergenHtml += '<span class="chip allergen">' + mapLabel(ALLERGEN, key, 'allergen') + '</span>';
          });
          allergenHtml += '</div>';
        }

        var nameMain = it.name || it.name_zh || '';
        var nameSub = (it.name && it.name_zh) ? it.name_zh : '';
        html += '<div class="item">'
          + '<div class="name">' + esc(nameMain) + '<span class="badge b-' + v.cls + '">' + mapLabel(VERDICT, it.verdict, 'verdict') + '</span></div>'
          + (nameSub ? '<div class="zh">' + esc(nameSub) + '</div>' : '')
          + '<div class="item-cat">' + (cat.icon || '🍽️') + ' ' + mapLabel(CATEGORY, it.category, 'category') + '</div>'
          + (it.texture ? '<div class="detail">' + esc(t('result.texture')) + ': ' + bi(it.texture, it.texture_zh) + '</div>' : '')
          + (it.risk ? '<div class="detail">' + esc(t('result.risk')) + ': ' + bi(it.risk, it.risk_zh) + '</div>' : '')
          + hazardHtml
          + allergenHtml
          + '</div>';
      });
    } else {
      html += '<pre class="raw">' + esc(raw || t('result.notFood')) + '</pre>';
    }

    $('result').innerHTML = html;
    $('resultCard').classList.remove('hidden');
    $('resultCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    showStatus(t('status.done'));
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
    showStatus(t('status.iphoneFullscreen'));
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
    navigator.serviceWorker.register('/sw.js').then(function (reg) {
      // Ask for a fresh copy of the worker on every load.
      if (reg.update) reg.update().catch(function () {});
    }).catch(function () {});
  });

  // When a new worker takes control, reload once so the new code runs.
  var swReloading = false;
  navigator.serviceWorker.addEventListener('controllerchange', function () {
    if (swReloading) return;
    swReloading = true;
    window.location.reload();
  });
}
</script>
</body>
</html>
