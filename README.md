# Food Check

A one-page, elderly-friendly camera app that checks whether a meal looks suitable for a
selected IDDSI diet level. The browser captures a photo; a PHP backend resizes it and
proxies the vision-language model (VLM) request, so the VLM key never reaches the browser
and CORS is no longer an issue.

Live site: **https://food-check.elpis.li/**

> Visual aid only. This is not a medical device and does not replace a doctor or speech
> therapist.

## Features

- IDDSI diet level selector: Liquid / Pureed / Minced & Moist / Soft.
- Pink theme with larger text and 60px touch targets; browser zoom is enabled.
- Centered rear-camera preview; tap it or **Check food** to capture; the camera stops and hides after a photo until **Open camera** is tapped.
- Browser text-to-speech reads the verdict, ingredient verdicts, and safer-food suggestions in
  English and Traditional Chinese, with Read and Stop buttons.
- Installable web app (PWA) with a pink food-bowl icon, manifest, and service worker.
- Server-side image preprocessing: validation, EXIF orientation, downscale to
  1000x1000 px max, transparency flattening and JPEG re-encoding.
- VLM JSON response normalised by the backend, with bilingual English and Chinese results.
- Structured food types (fish, meat, pork, poultry, seafood, vegetable, fruit, grain, dairy,
  egg, legume, nut), possible physical hazards (bones, fragments, seeds, skin, etc.) and
  possible allergens.
- General long-term health notes for diabetes, heart disease, stroke, kidney, liver, high
  blood pressure, high cholesterol, and gout, clearly marked as non-medical.
- Practical suggestions to make food safer: add moisture, steam/re-cook, mash/blend, cut smaller, remove skin/bones/seeds.
- Big bright red cross warning when the image is not food or not edible.
- Automatic fallback to a second vision model when the primary fails or rejects images.
- Memcached-backed circuit breaker: after a primary failure, the fallback is used directly for 5 minutes.
- Global abuse protection: at most one image analysis every 2 seconds across all clients.
- Rolling JSON-lines analysis log (`vlm.log`, last 5000 entries) plus saved processed images.
- Fixed backend endpoint (`/vlm-process.php`); no settings dialog or client-side configuration.
- Same-origin proxy by default, optional API key for external clients, optional CORS allow-list, per-IP rate limiting.

## Architecture

```
Browser (index.html)
   |  POST /vlm-process.php  { image: data URL, level, source }
   |  same-origin request (no key needed)
   v
vlm-process.php --> config.php --> config.secrets.php
   |  auth + per-IP limit + global 1-per-2s limit
   |  circuit breaker (Memcached; file fallback)
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
```

The browser never contacts the VLM directly, so there are no CORS errors and the VLM keys
stay on the server.

## Files

| File | Purpose |
| --- | --- |
| `index.html` | The whole frontend (UI, camera, and fetch to the backend). |
| `vlm-process.php` | Image preprocessor and VLM proxy; the only public API endpoint. |
| `config.php` | Loads and validates configuration, returns an array. |
| `config.secrets.php` | Deployment secrets (VLM credentials + backend API key). Gitignored, and returns 404 if requested directly. |
| `config.secrets.example.php` | Template for `config.secrets.php`. |
| `.env.example` | Template for an external env file (`FOOD_CHECK_ENV`); copy it **outside** the document root. |
| `.user.ini` | PHP-FPM upload and memory limits (applied after `user_ini.cache_ttl`, default 300s). |
| `.htaccess` | Apache hardening fallback; this site runs on nginx, which ignores it. |
| `manifest.json` | PWA manifest (name, pink theme, standalone display, icons). |
| `sw.js` | Service worker: network-first pages, cache-first static assets, API excluded. |
| `icon.svg`, `icon-192.png`, `icon-512.png` | App icons. |
| `icon-maskable.svg`, `icon-maskable-512.png` | Maskable Android icon source and PNG. |
| `apple-touch-icon.png` | iOS home-screen icon. |

## Web app, camera and speech

- **Installable PWA.** `manifest.json` sets a pink theme, standalone display and the food-bowl
  icons. On Android Chrome use **Install app**; on iOS Safari use **Share -> Add to Home
  Screen**. HTTPS is required (the site already uses it).
- **Service worker.** Pages are network-first, static assets are cache-first, and
  `/vlm-process.php` is never cached. Bump `CACHE` in `sw.js` when changing shell assets.
- **Accessibility.** Pink theme, 20px base text, a 20px high-contrast tip callout, 60px
  buttons, high contrast, and browser zoom is enabled. The result card uses
  `aria-live="polite"`.
- **Camera.** The portrait preview is centered. Tap the preview (or press **Check food**) to
  capture. After capture, the stream stops, the camera box hides, and the captured photo
  appears in the result card. **Check food** stays disabled until **Open camera** is tapped
  again.
- **Text to speech.** After a result, the app queues English (`en-US`) and Traditional
  Chinese (`zh-HK`) speech for the verdict, ingredient verdicts, food types, possible
  allergens and hazards, long-term health notes, and safer-food suggestions. **Read result**
  replays it and **Stop** cancels it. Auto-read may be blocked by browser policy; the button
  always works. Voice availability depends on the device.

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
| `APP_API_KEY` | none (required) | Key for non-browser/API clients, sent as `X-API-Key` or `Authorization: Bearer`. Same-origin page requests do not need it. |
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

Generate a key for external API clients:

```bash
openssl rand -hex 32
```

Put it in `config.secrets.php` as `APP_API_KEY`. The browser page does not need it:
same-origin requests are trusted, because the page itself is the client. Set
`APP_ALLOW_SAME_ORIGIN=0` if you want to require the key for every request.

The site is a static page, so the key cannot be kept secret inside the browser. Keeping it
out of `index.html` and requiring it only for non-same-origin clients avoids publishing it
while still protecting programmatic access.

### Model notes

The configured endpoint (`office-3080ti-8080.carryai.co`, model `qwen3.8-27b`) now
accepts images. The test request returned a valid JSON assessment with a 1000x750 resized
image.

The server limits output to **2048 tokens**. The backend therefore sets `max_tokens` to
2048 and disables Qwen3 reasoning tokens with
`chat_template_kwargs: {"enable_thinking": false}` (`VLM_DISABLE_THINKING=1`). This keeps
the answer from being truncated while leaving the full budget for the JSON result. Set
`VLM_DISABLE_THINKING=0` for a model that does not support that option.

The fallback endpoint (`https://opencode.ai/zen/go/v1`, model `minimax-m3.1-flash`) is
also verified as a vision model: it correctly identified a red test image. OpenCode Zen
requires an `x-opencode-session` header, so the backend sends a stable per-client session
ID for fallback requests. The fallback is tried automatically when the primary fails,
times out, or rejects images.

`deepseek-v4.1-flash` on the same endpoint is also verified as a vision model. To use it,
set `VLM_FALLBACK_MODEL=deepseek-v4.1-flash`; no other change is needed.

If every provider fails or rejects images, the backend returns the last upstream error.

## Food analysis details

The backend normalises four extra layers of information. They are visual estimates, not
laboratory results.

### Food types

Each item has one `category`: `fish`, `meat`, `pork`, `poultry`, `seafood`, `vegetable`,
`fruit`, `grain`, `dairy`, `egg`, `legume`, `nut`, or `other`. The UI shows chips for the
dish's unique `food_types` and a category under each ingredient.

### Possible hazards

`hazards` lists possible physical hazards: `bones`, `small_bones`, `fish_bones`,
`shell_fragments`, `hard_fragments`, `seeds`, `skin`, `cartilage`, `pits`, `spines`,
`scales`, `nuts`. The UI shows them as orange chips. They are possibilities from the photo,
not confirmed.

### Possible allergens

`allergens` uses the common keys `milk`, `egg`, `fish`, `shellfish`, `peanut`, `tree_nut`,
`soy`, `wheat`, `sesame`. Per-item lists are aggregated into the dish-level `allergens` and
shown in a prominent orange warning card. A photo cannot confirm ingredients; always check
the actual ingredients, sauces, marinades, cross-contact, and the person's allergy plan.

### Long-term health notes

`health_notes` contains general, non-diagnostic notes for `diabetes`, `heart_disease`,
`stroke`, `kidney_disease`, `liver_disease`, `hypertension`, `high_cholesterol`, and `gout`.
Each note has a `level` of `caution`, `avoid`, or `unknown`, plus a short reason in English
and Traditional Chinese. The model only returns conditions with a plausible concern from
the visible food. Examples:

- **Diabetes:** refined carbohydrates, sweet sauces, large portions.
- **Heart disease / stroke / high blood pressure / high cholesterol:** salt, fried or fatty
  food, processed meat.
- **Kidney disease:** high-sodium or high-potassium foods, processed foods, dairy, nuts,
  beans.
- **Liver disease:** alcohol, high-fat or fried food, raw shellfish.
- **Gout:** red meat, organ meat, shellfish, beer, sugary drinks.

These are general ideas, not medical advice.

### Safety disclaimer

- A **doctor** manages medical conditions; a **dietitian / nutritionist** advises on
  nutrition and long-term conditions. The app is not a substitute for either.
- It cannot confirm ingredients or allergens from a photo. If the person has a food allergy,
  check with the kitchen and follow the allergy plan.
- Health notes are general information only, not a diagnosis or treatment plan.

## Circuit breaker and rate limiting

- When the primary fails (network error, HTTP error, timeout, empty response, or an image
  rejection), the backend writes `vlm:primary:failed:<hash>` to Memcached with a TTL of
  `VLM_FAILURE_TTL` seconds (default **300**).
- While the marker exists, requests skip the primary and use the fallback immediately. The
  response reports `primary_skipped: true`.
- A successful primary request deletes the marker.
- The marker is scoped to the primary base URL and model, so changing providers starts with
  a clean state.
- If Memcached is unavailable, the same marker is stored in a small file cache under
  `/tmp/food-check-cache`; the request still works.
- After authentication, an atomic Memcached `add` enforces one image analysis per
  `APP_GLOBAL_MIN_INTERVAL` seconds (default **2**) across all clients. Excess requests
  receive `429 rate_limited` with a `Retry-After` header. The per-IP limit still applies.
- If the cache cannot be reached at all, the check fails open rather than blocking the app.

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

Example (truncated):

```json
{"datetime":"2026-11-16T15:07:55+00:00","source":"camera","level":"soft","status":"success","model":"qwen3.8-27b","provider":"primary","api":"https://office-3080ti-8080.carryai.co/v1","primary_skipped":false,"duration_ms":5400,"vlm_ms":5346,"tokens":{"prompt":1515,"completion":612,"total":2127,"reasoning":0,"cached":0},"tokens_per_second":114.5,"is_food":true,"is_edible":true,"overall":"caution","image":{"path":"/var/tmp/food-check/uploads/20261116_150755_aa17a4fc5443.jpg","width":960,"height":870,"bytes":138988},"attempts":[{"provider":"primary","model":"qwen3.8-27b","ms":5346,"ok":true}],"prompt":"...","result":{...},"raw":"..."}
```

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

## API

### `POST /vlm-process.php`

Headers:

```
Content-Type: application/json
X-API-Key: <APP_API_KEY>
```

`X-API-Key` is required for non-browser clients such as `curl` and for other origins.
Requests made by the page itself are same-origin and do not need a key.

Body:

```json
{
  "image": "data:image/jpeg;base64,...",
  "level": "soft",
  "source": "camera",
  "model": "optional-model-override"
}
```

`level` is one of `liquid`, `puree`, `minced`, or `soft` (default `soft`). `source` is an
optional name recorded in `vlm.log`; multipart requests default to the uploaded filename.

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
condition notes with `level` of `caution`, `avoid`, or `unknown`. See **Food analysis
details** above for the key lists and limitations.

`provider` is `primary` or `fallback`, `fallback_used` is `true` when the fallback
model produced the answer, and `primary_skipped` is `true` when the circuit breaker
bypassed the primary.

Errors are JSON with an HTTP status:

```json
{ "ok": false, "error": { "code": "unauthorized", "message": "Missing or invalid API key." } }
```

| Status | Code | Meaning |
| --- | --- | --- |
| 400 | `invalid_json`, `invalid_image`, `invalid_level` | Bad request. |
| 401 | `unauthorized` | Missing or wrong `X-API-Key` for a non-same-origin request. |
| 405 | `method_not_allowed` | Use `POST`. |
| 413 | `image_too_large`, `request_too_large` | Payload too large. |
| 415 | `unsupported_type`, `unsupported_content_type` | Not JPEG/PNG/WebP or wrong body type. |
| 422 | `image_too_small`, `image_too_large` | Bad image dimensions. |
| 429 | `rate_limited` | Per-IP or global limit (1 image per 2 seconds by default); see `Retry-After`. |
| 500 | `server_misconfigured`, `internal_error` | Check `config.secrets.php`. |
| 502 | `upstream_unreachable`, `upstream_error`, `vision_not_supported`, `empty_response` | VLM problem. |

An `OPTIONS` preflight returns `204` when the origin is allowed.

## Image preprocessing

1. Verify the base64 or file payload and MIME type with `finfo`.
2. Reject dimensions below 16 px, above 12,000 px, or above 30 megapixels.
3. Apply EXIF orientation for JPEG.
4. Downscale (never upscale) so the longest side is at most `APP_MAX_DIMENSION` (1000).
5. Flatten alpha onto white and re-encode as JPEG at `APP_JPEG_QUALITY` (85).
6. Strip metadata; send only the resized image to the VLM as a base64 data URL.

The browser caps uploads at 1920 px and JPEG 0.9 to keep mobile uploads small; the backend
performs the authoritative 1000 px resize.

## Security notes

- VLM credentials live only on the server.
- Fallback VLM credentials live only on the server as well.
- `config.secrets.php` is a PHP file with a direct-access guard; requesting it returns 404.
- The old `.env` was removed from the web root. Use `FOOD_CHECK_ENV` pointing outside the
  document root if you prefer an env file.
- CORS is same-origin by default; add origins to `APP_ALLOWED_ORIGINS` only when needed.
- Same-origin page requests are trusted; set `APP_ALLOW_SAME_ORIGIN=0` to require
  `APP_API_KEY` for every request.
- Per-IP rate limiting uses files in the system temp directory; the global 1-per-2s limit
  and the primary-failure breaker use Memcached (file fallback if unavailable).
- Uploaded images and `vlm.log` are kept outside the public web root with restricted
  permissions. API keys and base64 image data are never written to the log.
- Uploads are capped and validated; remote image URLs are not accepted (no SSRF).
- Source image names are sanitized before logging; generated image filenames are random.
- The repository `.git` directory is mode `700`, so nginx (running as `www-data`) cannot
  serve repository metadata.
- Responses use `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`.

## Requirements

- PHP 8.1+ with `gd`, `curl`, `fileinfo`, `json`, and `exif` (all present here: PHP 8.5).
- PHP `memcached` extension and a Memcached server for the shared circuit breaker and
  global rate limit (optional; a file cache in `/tmp/food-check-cache` is used as fallback).
- PHP-FPM behind nginx/openresty.
- Permission for the PHP user to read `config.secrets.php`, write to the system temp
  directory (`/tmp`), and write to `APP_LOG_DIR` (default `/var/tmp/food-check`).
- `client_max_body_size` of at least 10 MB (already set globally); 30 MB on HTTPS.
- HTTPS for camera access in mobile browsers (`getUserMedia` requires a secure context).

## Local development

```bash
cd /var/www/html/elpis.li/food-check
php -S 127.0.0.1:8080
```

Then open `http://127.0.0.1:8080/`. Camera access needs `https://` or `localhost`. To
allow a different frontend origin, set `APP_ALLOWED_ORIGINS` in `config.secrets.php` (or
your external env file).

## Verification

The following checks were run against the live endpoint:

- Primary and fallback both returned valid assessments for an apple test image.
- Apple test: returned `food_types: ["fruit"]`, `hazards: ["seeds","skin"]`, and a
  diabetes caution note.
- Fish test: returned `food_types: ["fish","vegetable","fruit"]`, `allergens: ["fish"]`,
  `fish_bones` for the salmon, and gout/high-cholesterol cautions.
- A mocked renderer and speech engine confirmed the food-type chips, allergen warning,
  per-item hazard/allergen chips, long-term health-note cards, and the English/Chinese TTS
  for all four layers.
- `minimax-m3.1-flash` and `deepseek-v4.1-flash` both correctly answered an image-colour
  prompt, confirming they accept image input.
- Global limiter: two immediate requests returned `400` (invalid test image) followed by
  `429 rate_limited`.
- Circuit breaker read path: a pre-set failure marker caused `primary_skipped: true` and
  `provider: "fallback"`.
- Circuit breaker write path: forcing the primary offline produced a fallback result, stored
  the failure marker for 300 seconds, and recorded both attempts in the log.
- Log rotation: the rolling cap was verified with a reduced limit; production keeps the
  last 5000 entries.
- Logging: the processed image was saved, and the log captured prompt, result, model,
  provider, API, attempts, duration, token counts, token speed, and image metadata.
- PWA: `manifest.json`, `sw.js`, the SVG icon and the PNG icons all returned `200` with the
  expected MIME types.
- TTS: a mocked speech engine confirmed English (`en-US`) and Traditional Chinese (`zh-HK`)
  utterances for the verdict, ingredient verdicts, safer-food suggestions, and the
  non-food warning.
- PHP lint and JavaScript syntax checks pass.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `401 unauthorized` | Requests from the page itself normally pass. If you are calling from `curl` or another origin, send `X-API-Key`. |
| `vision_not_supported` | The model rejects images; set `VLM_MODEL` to a vision-capable model. |
| `upstream_unreachable` | Check `VLM_BASE_URL`, network access, and `VLM_API_KEY`. |
| `rate_limited` | Wait for `Retry-After`. The default global limit is one analysis every 2 seconds; adjust `APP_GLOBAL_MIN_INTERVAL` or the per-IP `APP_RATE_LIMIT`. |
| `primary_skipped: true` unexpectedly | The circuit breaker remembers a primary failure for `VLM_FAILURE_TTL` (300s). Wait for expiry, or delete the Memcached key. |
| Fallback is not used | Set `VLM_FALLBACK_BASE_URL`, `VLM_FALLBACK_MODEL`, and `VLM_FALLBACK_API_KEY`, and keep `VLM_FALLBACK_ENABLED=1`. |
| No `vlm.log` entry | Check that `APP_LOG_DIR` (default `/var/tmp/food-check`) is writable by the PHP user. Logs are not web-accessible. |
| Memcached unavailable | The code falls back to `/tmp/food-check-cache`; check the Memcached service if you need shared state across PHP workers. |
| Camera does not start | Use HTTPS, allow camera permission, and check the browser console. |
| Upload exceeds the PHP limit | Multipart uploads use `.user.ini`; PHP-FPM applies it after up to 300s (reload PHP-FPM to apply immediately). The JSON path uses `post_max_size`. |
