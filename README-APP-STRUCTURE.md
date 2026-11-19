# Food Check - app structure

Technical reference for the Food Check backend and web app: architecture, files,
configuration, the API, the image pipeline, the web app, logging and local development.

- **README.md** - what the app does, how it works and what information it gives out.
- **README-SECURITY.md** - authentication, tokens, rate limits and privacy.
- **README-OTHERS.md** - model notes, verification, troubleshooting, requirements.

## Architecture

```
Browser (index.php)
   |  POST /vlm-process.php  { image: data URL, level, source }
   |  X-VLM-Token: <signed token>   (or X-API-Key for API clients)
   v
vlm-process.php --> config.php --> config.secrets.php
   |  validate + resize image (GD) + save processed image
   |  POST /chat/completions
   +--> VLM primary (VLM_BASE_URL)
   |       on failure: remember failure for 5 min, then try fallback
   +--> VLM fallback (VLM_FALLBACK_BASE_URL)
   |
   +--> /var/tmp/food-check/vlm.log  (last 5000 JSON lines)
   +--> /var/tmp/food-check/uploads/ (processed JPEGs)
   v
normalised { ok, result, raw, usage, image, provider, ... } to the browser

token.php        issues short-lived signed tokens to same-origin browsers
log/index.php    log viewer (asks for the API key once)
log/api.php      last N log entries as JSON (API key required)
lib.php          shared helpers (JSON, cache, rate limit, tokens)
sw.js            service worker (static assets only)
```

The browser never contacts the VLM directly, so there are no CORS errors and the VLM keys
stay on the server.

## Files

| File | Purpose |
| --- | --- |
| `index.php` | The whole frontend. PHP so it can embed a signed token for the API. |
| `lang.js` | The answer-language table (95 languages) shared with `lib.php`. |
| `fonts/nunito-latin.woff2`, `fonts/nunito-latin-ext.woff2` | Self-hosted rounded font (Latin only, so Chinese keeps the system face). |
| `vlm-process.php` | Image preprocessor and VLM proxy; the only public analysis endpoint. |
| `token.php` | Issues short-lived signed tokens to same-origin browser requests. |
| `lib.php` | Shared helpers: JSON responses, error class, Memcached/file cache, rate limiting, image/log helpers, token signing and verification. |
| `config.php` | Loads and validates configuration, returns an array. |
| `config.secrets.php` | Deployment secrets (VLM credentials, API key, token secret). Gitignored, and returns 404 if requested directly. |
| `config.secrets.example.php` | Template for `config.secrets.php`. |
| `log/index.php` | Log viewer for the last 30 analyses. |
| `log/api.php` | Last N log entries as JSON (requires the API key). |
| `manifest.json` | PWA manifest (name, pink theme, standalone display, icons). |
| `sw.js` | Service worker: network-first pages, cache-first static assets, API excluded. |
| `icon.svg`, `icon-192.png`, `icon-512.png` | App icons. |
| `icon-maskable.svg`, `icon-maskable-512.png` | Maskable Android icon source and PNG. |
| `apple-touch-icon.png` | iOS home-screen icon. |
| `.env.example` | Template for an external env file (`FOOD_CHECK_ENV`); copy it **outside** the document root. |
| `.user.ini` | PHP-FPM upload and memory limits (applied after `user_ini.cache_ttl`, default 300s). |
| `.htaccess` | Apache hardening fallback; this site runs on nginx, which ignores it. |

## Configuration

Secrets are read with this priority:

1. Real environment variables (for example, set in the PHP-FPM pool).
2. `config.secrets.php` (default for this deployment).
3. A file named by the `FOOD_CHECK_ENV` environment variable (keep it outside the web root).

For a fresh deployment, copy the template and fill in the values:

```bash
cp config.secrets.example.php config.secrets.php
chmod 640 config.secrets.php
```

| Variable | Default | Meaning |
| --- | --- | --- |
| `APP_API_KEY` | none (required) | Key for API clients, sent as `X-API-Key` or `Authorization: Bearer`. Browser requests use a signed token instead. |
| `APP_TOKEN_SECRET` | none | Secret used to sign browser tokens. Use a different value from `APP_API_KEY`. |
| `APP_TOKEN_TTL` | `300` | Token lifetime in seconds. |
| `APP_TOKEN_MIN_REFRESH` | `30` | Refresh the token this many seconds before it expires. |
| `VLM_BASE_URL` | none (required) | OpenAI-compatible base URL, for example `https://host/v1`. |
| `VLM_MODEL` | none (required) | Model name. **It must support image input.** |
| `VLM_API_KEY` | none (required) | Upstream VLM key (server-side only). |
| `VLM_FALLBACK_BASE_URL` | empty | Optional fallback OpenAI-compatible base URL. |
| `VLM_FALLBACK_MODEL` | empty | Optional fallback vision model. |
| `VLM_FALLBACK_API_KEY` | empty | Fallback VLM key (server-side only). |
| `VLM_FALLBACK_ENABLED` | `1` | Enable the fallback when all three fallback values are set. |
| `VLM_FALLBACK_TIMEOUT` | `45` | Fallback request timeout in seconds. |
| `VLM_FALLBACK_DISABLE_THINKING` | `0` | Disable reasoning tokens for the fallback model. |
| `VLM_FAILURE_TTL` | `300` | Seconds to skip the primary after a failure (circuit breaker). |
| `APP_MAX_DIMENSION` | `1000` | Longest side of the image sent to the VLM. |
| `APP_JPEG_QUALITY` | `85` | Re-encode quality (50-95). |
| `APP_ALLOWED_ORIGINS` | empty | Comma-separated CORS origins; empty means same-origin only, `*` allows any origin. |
| `APP_ALLOW_SAME_ORIGIN` | `1` | Let the browser page call the proxy without an API key. Set to `0` to require the key everywhere. |
| `APP_RATE_LIMIT` | `60` | Requests per IP per window; `0` disables the limit. |
| `APP_RATE_WINDOW` | `60` | Rate-limit window in seconds. |
| `APP_GLOBAL_MIN_INTERVAL` | `2` | Minimum seconds between image analyses across all clients; `0` disables it. |
| `MEMCACHED_HOST` | `127.0.0.1` | Memcached host for the breaker and global rate limit. |
| `MEMCACHED_PORT` | `11211` | Memcached port. |
| `MEMCACHED_PREFIX` | `food-check:` | Key prefix shared by cache entries. |
| `APP_LOG_DIR` | `/var/tmp/food-check` | Log and uploaded-image directory (outside the web root). |
| `APP_LOG_MAX_LINES` | `5000` | Number of `vlm.log` entries to keep. |
| `APP_LOG_IMAGES` | `1` | Set to `0` to stop saving processed images. |
| `VLM_TIMEOUT` | `90` | Upstream request timeout in seconds. |
| `VLM_MAX_TOKENS` | `2048` | Maximum output tokens (the current server limit). |
| `VLM_TEMPERATURE` | `0.2` | Sampling temperature. |
| `VLM_DISABLE_THINKING` | `1` | Disable Qwen3 reasoning tokens so the output budget is used for JSON. |

## Web app, camera and speech

- **Installable PWA.** `manifest.json` sets a pink theme, `display_override` of
  `fullscreen / standalone / minimal-ui` and the food-bowl icons. On Android Chrome use
  **Install app**; on iOS Safari use **Share -> Add to Home Screen**. HTTPS is required
  (the site already uses it).
- **Full screen.** The installed app runs without browser chrome. The **full screen**
  button in the header calls the Fullscreen API and is hidden automatically when the page
  is already full screen or running as an installed standalone app. On iPhone, where the
  Fullscreen API is unavailable, the button explains how to add the app to the home screen.
- **Safe areas and layout.** `viewport-fit=cover` plus `env(safe-area-inset-*)` padding
  keeps the header and the bottom of the page clear of notches and home indicators.
  `100dvh` sizing and `overscroll-behavior: none` stop the rubber-band scroll on iOS.
- **Service worker.** Static assets are cache-first; the page, `token.php`, `vlm-process.php`
  and the log viewer are never cached. Bump `CACHE` in `sw.js` when changing shell assets.
- **Accessibility.** Pink theme by default (14 themes available), 20px base text, a 20px
  high-contrast tip callout, 60px buttons, and browser zoom is enabled. The result card uses
  `aria-live="polite"`.
- **Settings panel.** The ⚙ button in the header opens a panel with answer language,
  long-term conditions, text size, theme, text colour, font type and speech. Each group
  shows the common choices first and hides the rest behind a **More 更多** toggle. The
  profile is stored in `localStorage` (mirrored to a cookie) and applied before the first
  paint by a small inline script, so the saved theme survives a refresh without flashing.
- **Answer language.** 95 languages. The backend tells the model which language to write
  in and which JSON fields to leave empty, and enables the model's reasoning mode for
  single-language answers because it follows the instruction more reliably that way.
- **Camera.** The portrait preview is centered. Tap the preview (or press **Check food**) to
  capture. After capture, the stream stops, the camera box hides, and the captured photo
  appears in the result card. **Check food** stays disabled until **Open camera** is tapped
  again.
- **Text to speech.** After a result, the app queues English (`en-US`) and Traditional
  Chinese (`zh-HK`) speech for the verdict, ingredient verdicts, food types, possible
  allergens and hazards, long-term health notes, and safer-food suggestions. **Read result**
  replays it and **Stop** cancels it. Auto-read may be blocked by browser policy; the button
  always works. Voice availability depends on the device.

## API

### `POST /vlm-process.php`

Headers:

```
Content-Type: application/json
X-VLM-Token: <signed token>      # browser
X-API-Key: <APP_API_KEY>          # API clients
```

`X-VLM-Token` and `X-API-Key` are both accepted; at least one valid credential is
required. See **README-SECURITY.md** for the token rules.

Body:

```json
{
  "image": "data:image/jpeg;base64,...",
  "level": "soft",
  "source": "camera",
  "language": "both",
  "conditions": ["diabetes", "kidney_disease"],
  "model": "optional-model-override"
}
```

`level` is one of `liquid`, `puree`, `minced`, or `soft` (default `soft`). `source` is an
optional name recorded in `vlm.log`; multipart requests default to the uploaded filename.

`language` is one of `en`, `zh`, `yue`, `zh_cn`, `es`, `fr`, `de`, `ja`, `ko`, `both` and the
other codes listed in `fc_languages()` (`lib.php`). It decides the language of the answer and
which JSON fields the model fills. `conditions` is a list of condition keys
(`diabetes`, `heart_disease`, `stroke`, `kidney_disease`, `liver_disease`, `hypertension`,
`high_cholesterol`, `gout`) and narrows the health notes and suggestions to those conditions.

`multipart/form-data` is also accepted with an `image` file plus `level` and `model`
fields. JPEG, PNG, and WebP are supported; images smaller than 16 px, larger than
12,000 px per side, or larger than 30 megapixels are rejected.

Example:

```bash
curl -s https://food-check.elpis.li/vlm-process.php \
  -H 'Content-Type: application/json' \
  -H 'X-API-Key: fc_...' \
  --data-binary '{"image":"data:image/jpeg;base64,<BASE64>","level":"soft"}'
```

Success:

```json
{
  "ok": true,
  "result": {
    "dish": "Grilled salmon with vegetables",
    "dish_zh": "<Chinese translation>",
    "food_types": ["fish", "vegetable"],
    "allergens": ["fish"],
    "is_food": true,
    "is_edible": true,
    "overall": "caution",
    "overall_en": "Salmon is soft, but check for bones and a salty sauce.",
    "overall_zh": "<Chinese translation>",
    "warning_en": "",
    "warning_zh": "",
    "modifications": [
      {
        "en": "Remove all fish bones and skin",
        "zh": "<Chinese translation>"
      },
      {
        "en": "Steam or simmer until fork-soft",
        "zh": "<Chinese translation>"
      },
      {
        "en": "Use a low-salt sauce",
        "zh": "<Chinese translation>"
      }
    ],
    "health_notes": [
      {
        "condition": "hypertension",
        "level": "caution",
        "reason_en": "Soy sauce and seasoning may be high in salt.",
        "reason_zh": "<Chinese translation>"
      },
      {
        "condition": "kidney_disease",
        "level": "caution",
        "reason_en": "Salty sauces may be high in sodium.",
        "reason_zh": "<Chinese translation>"
      },
      {
        "condition": "gout",
        "level": "caution",
        "reason_en": "Fish is a purine-rich food that may trigger gout.",
        "reason_zh": "<Chinese translation>"
      }
    ],
    "items": [
      {
        "name": "Salmon",
        "name_zh": "<Chinese translation>",
        "category": "fish",
        "hazards": ["fish_bones", "skin"],
        "allergens": ["fish"],
        "texture": "soft and flaky",
        "texture_zh": "<Chinese translation>",
        "risk": "fish bones and skin",
        "risk_zh": "<Chinese translation>",
        "verdict": "caution"
      },
      {
        "name": "Steamed vegetables",
        "name_zh": "<Chinese translation>",
        "category": "vegetable",
        "hazards": [],
        "allergens": [],
        "texture": "soft",
        "texture_zh": "<Chinese translation>",
        "risk": "check for hard stems",
        "risk_zh": "<Chinese translation>",
        "verdict": "suitable"
      }
    ]
  },
  "raw": "<original model reply>",
  "level": "soft",
  "model": "qwen3.8-27b",
  "provider": "primary",
  "fallback_used": false,
  "primary_skipped": false,
  "usage": { "prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0 },
  "image": {
    "width": 1000,
    "height": 667,
    "bytes": 91234,
    "mime": "image/jpeg",
    "source_width": 2400,
    "source_height": 1600
  }
}
```

If the model does not return parseable JSON, `result` is `null` and `raw` contains the
reply; the frontend shows the raw text.

`is_food` and `is_edible` are normalised to booleans. When either is `false`, the frontend
shows a large bright red cross with the `warning_en` / `warning_zh` text instead of the
normal ingredient list. `modifications` is a list of practical changes (at most 4) that may
make the food safer for the selected IDDSI level.

`food_types` and `allergens` are aggregated from the items. Each item carries its own
`category`, `hazards`, and `allergens`. `health_notes` contains general, non-medical
condition notes with `level` of `caution`, `avoid`, or `unknown`.

`provider` is `primary` or `fallback`, `fallback_used` is `true` when the fallback
model produced the answer, and `primary_skipped` is `true` when the circuit breaker
bypassed the primary.

Errors are JSON with an HTTP status:

```json
{ "ok": false, "error": { "code": "unauthorized", "message": "A valid API key or signed token is required." } }
```

| Status | Code | Meaning |
| --- | --- | --- |
| 400 | `invalid_json`, `invalid_image`, `invalid_level` | Bad request. |
| 401 | `unauthorized` | Missing or invalid `X-VLM-Token` / `X-API-Key`. |
| 403 | `origin_not_allowed` | Token request from another origin. |
| 405 | `method_not_allowed` | Use `POST`. |
| 413 | `image_too_large`, `request_too_large` | Payload too large. |
| 415 | `unsupported_type`, `unsupported_content_type` | Not JPEG/PNG/WebP or wrong body type. |
| 422 | `image_too_small`, `image_too_large` | Bad image dimensions. |
| 429 | `rate_limited` | Per-IP or global limit (1 image per 2 seconds by default); see `Retry-After`. |
| 500 | `server_misconfigured`, `internal_error` | Check `config.secrets.php`. |
| 502 | `upstream_unreachable`, `upstream_error`, `vision_not_supported`, `empty_response` | VLM problem. |

### `POST /token.php`

Issues a short-lived signed token. Same-origin requests only.

```json
{ "ok": true, "token": "...", "issued_at": 0, "expires_at": 0, "ttl": 300 }
```

### `GET /log/api.php`

Returns the last N log entries (default 30). Requires the API key.

```bash
curl -s https://food-check.elpis.li/log/api.php -H 'X-API-Key: fc_...' | jq .
```

The human-friendly viewer is at **https://food-check.elpis.li/log/** - it asks for the API
key once and keeps it in `sessionStorage` only.

## Image preprocessing

1. Verify the base64 or file payload and MIME type with `finfo`.
2. Reject dimensions below 16 px, above 12,000 px, or above 30 megapixels.
3. Apply EXIF orientation for JPEG.
4. Downscale (never upscale) so the longest side is at most `APP_MAX_DIMENSION` (1000).
5. Flatten alpha onto white and re-encode as JPEG at `APP_JPEG_QUALITY` (85).
6. Strip metadata; send only the resized image to the VLM as a base64 data URL.

The browser caps uploads at 1920 px and JPEG 0.9 to keep mobile uploads small; the backend
performs the authoritative 1000 px resize.

## Logging and uploaded images

The default log directory is `/var/tmp/food-check`, deliberately **outside the public web
root**:

```
/var/tmp/food-check/
  vlm.log       JSON Lines; one entry per request; last 5000 entries
  vlm.log.count internal line counter
  uploads/      processed JPEGs that were sent to the VLM
```

Each `vlm.log` entry includes:

- `datetime`, `finished_at`, `source`, `level`, `status`
- `prompt` (text only, no base64 image) and `result` / `raw` model output
- `model`, `provider` (`primary` or `fallback`), `api`, `attempts`
- `primary_skipped`
- `duration_ms` (whole request) and `vlm_ms` (provider call)
- `tokens`: prompt, completion, total, reasoning, cached
- `tokens_per_second`
- `image`: saved path, width, height, bytes, MIME, source dimensions
- `is_food`, `is_edible`, `overall`
- `error` details for failed calls

Inspect the latest entries:

```bash
tail -n 50 /var/tmp/food-check/vlm.log | jq .
ls -lh /var/tmp/food-check/uploads/
```

Notes:

- Only the processed image (resized, EXIF-stripped JPEG) is stored, not the original
  upload. Set `APP_LOG_IMAGES=0` to disable image retention.
- Directories are created with group access (`0770`) and files with `0640`, so the site
  owner can inspect them without making them web-readable.
- Source names are sanitized; API keys and the base64 image are never written to the log.
- If `APP_LOG_DIR` is not writable, the backend falls back to `/tmp/food-check`.
- `APP_LOG_MAX_LINES` is clamped to at least 100; the default is 5000.

## Local development

```bash
cd /var/www/html/elpis.li/food-check
php -S 127.0.0.1:8080
```

Then open `http://127.0.0.1:8080/`. Camera access needs `https://` or `localhost`. To
allow a different frontend origin, set `APP_ALLOWED_ORIGINS` in `config.secrets.php` (or
your external env file).

The built-in PHP server does not process `.htaccess`, and `index.php` still works.
