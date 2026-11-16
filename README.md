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
- Rear-camera capture with a large 52px+ button UI.
- Server-side image preprocessing: validation, EXIF orientation, downscale to
  1000x1000 px max, transparency flattening and JPEG re-encoding.
- VLM JSON response normalised by the backend, with bilingual English and Chinese results.
- Settings modal (gear icon) for the backend API key and optional model override.
- Same-origin proxy by default, optional CORS allow-list, per-IP rate limiting.

## Architecture

```
Browser (index.html)
   |  POST /vlm-process.php  { image: data URL, level }
   |  X-API-Key: <APP_API_KEY>
   v
vlm-process.php --> config.php --> config.secrets.php
   |  validate + resize image (GD)
   |  POST /chat/completions
   v
VLM server (VLM_BASE_URL)  -->  JSON result
   ^
   +-- normalised { ok, result, raw, usage, image } to the browser
```

The browser never contacts the VLM directly, so there are no CORS errors and the VLM key
stays on the server.

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
| `APP_API_KEY` | none (required) | Key the browser sends as `X-API-Key` or `Authorization: Bearer`. |
| `VLM_BASE_URL` | none (required) | OpenAI-compatible base URL, for example `https://host/v1`. |
| `VLM_MODEL` | none (required) | Model name. **It must support image input.** |
| `VLM_API_KEY` | none (required) | Upstream VLM key (server-side only). |
| `APP_MAX_DIMENSION` | `1000` | Longest side of the image sent to the VLM. |
| `APP_JPEG_QUALITY` | `85` | Re-encode quality (50-95). |
| `APP_ALLOWED_ORIGINS` | empty | Comma-separated CORS origins; empty means same-origin only, `*` allows any origin. |
| `APP_RATE_LIMIT` | `60` | Requests per IP per window; `0` disables the limit. |
| `APP_RATE_WINDOW` | `60` | Rate-limit window in seconds. |
| `VLM_TIMEOUT` | `90` | Upstream request timeout in seconds. |
| `VLM_MAX_TOKENS` | `2048` | Maximum output tokens (the current server limit). |
| `VLM_TEMPERATURE` | `0.2` | Sampling temperature. |
| `VLM_DISABLE_THINKING` | `1` | Disable Qwen3 reasoning tokens so the output budget is used for JSON. |

Generate a new backend key:

```bash
openssl rand -hex 32
```

Put it in `config.secrets.php` as `APP_API_KEY`, then enter the **same value** once per
device in the app: **Settings (gear icon) -> Backend API Key -> Save**. It is stored in
`localStorage`; the backend answers `401` if it is missing or wrong.

The site is a static page, so a key typed by the user is the only way to avoid publishing
the key in the HTML source. If you embed it in JavaScript, treat it as public and rely on
the rate limit.

### Model notes

The configured endpoint (`office-3080ti-8080.carryai.co`, model `qwen3.8-27b`) now
accepts images. The test request returned a valid JSON assessment with a 1000x750 resized
image.

The server limits output to **2048 tokens**. The backend therefore sets `max_tokens` to
2048 and disables Qwen3 reasoning tokens with
`chat_template_kwargs: {"enable_thinking": false}` (`VLM_DISABLE_THINKING=1`). This keeps
the answer from being truncated while leaving the full budget for the JSON result. Set
`VLM_DISABLE_THINKING=0` for a model that does not support that option.

If an upstream model still rejects images, the backend returns a clear
`vision_not_supported` error. Point `VLM_BASE_URL` and `VLM_MODEL` at a vision-capable
model in that case.

## API

### `POST /vlm-process.php`

Headers:

```
Content-Type: application/json
X-API-Key: <APP_API_KEY>
```

Body:

```json
{
  "image": "data:image/jpeg;base64,...",
  "level": "soft",
  "model": "optional-model-override"
}
```

`level` is one of `liquid`, `puree`, `minced`, or `soft` (default `soft`).

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
    "dish": "Chicken rice",
    "dish_zh": "<Chinese translation>",
    "overall": "caution",
    "overall_en": "Chicken is soft but rice may be dry and sticky.",
    "overall_zh": "<Chinese translation>",
    "items": [
      {
        "name": "Steamed chicken",
        "name_zh": "<Chinese translation>",
        "texture": "soft and moist",
        "texture_zh": "<Chinese translation>",
        "risk": "remove skin and bones",
        "risk_zh": "<Chinese translation>",
        "verdict": "suitable"
      }
    ]
  },
  "raw": "<original model reply>",
  "level": "soft",
  "model": "qwen3.8-27b",
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

Errors are JSON with an HTTP status:

```json
{ "ok": false, "error": { "code": "unauthorized", "message": "Invalid or missing API key. Add the backend key in Settings." } }
```

| Status | Code | Meaning |
| --- | --- | --- |
| 400 | `invalid_json`, `invalid_image`, `invalid_level` | Bad request. |
| 401 | `unauthorized` | Missing or wrong `X-API-Key`. |
| 405 | `method_not_allowed` | Use `POST`. |
| 413 | `image_too_large`, `request_too_large` | Payload too large. |
| 415 | `unsupported_type`, `unsupported_content_type` | Not JPEG/PNG/WebP or wrong body type. |
| 422 | `image_too_small`, `image_too_large` | Bad image dimensions. |
| 429 | `rate_limited` | Slow down; see `Retry-After`. |
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
- `config.secrets.php` is a PHP file with a direct-access guard; requesting it returns 404.
- The old `.env` was removed from the web root. Use `FOOD_CHECK_ENV` pointing outside the
  document root if you prefer an env file.
- CORS is same-origin by default; add origins to `APP_ALLOWED_ORIGINS` only when needed.
- Per-IP rate limiting uses files in the system temp directory (no database required).
- Uploads are capped and validated; remote image URLs are not accepted (no SSRF).
- The repository `.git` directory is mode `700`, so nginx (running as `www-data`) cannot
  serve repository metadata.
- Responses use `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`.

## Requirements

- PHP 8.1+ with `gd`, `curl`, `fileinfo`, `json`, and `exif` (all present here: PHP 8.5).
- PHP-FPM behind nginx/openresty.
- Permission for the PHP user to read `config.secrets.php` and write to the system temp
  directory (`/tmp`).
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

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| "Please enter the backend API key" or `401` | Copy `APP_API_KEY` from `config.secrets.php` into Settings -> Backend API Key. |
| `vision_not_supported` | The model rejects images; set `VLM_MODEL` to a vision-capable model. |
| `upstream_unreachable` | Check `VLM_BASE_URL`, network access, and `VLM_API_KEY`. |
| `rate_limited` | Wait for `Retry-After`, or raise `APP_RATE_LIMIT`. |
| Camera does not start | Use HTTPS, allow camera permission, and check the browser console. |
| Upload exceeds the PHP limit | Multipart uploads use `.user.ini`; PHP-FPM applies it after up to 300s (reload PHP-FPM to apply immediately). The JSON path uses `post_max_size`. |
