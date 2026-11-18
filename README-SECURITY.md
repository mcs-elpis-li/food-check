# Food Check - security

How the Food Check backend is protected: authentication, signed tokens, CORS, rate limits,
the circuit breaker, log privacy and a checklist for deployments.

- **README.md** - what the app does, how it works and what information it gives out.
- **README-APP-STRUCTURE.md** - architecture, files, configuration, API and logging.
- **README-OTHERS.md** - model notes, verification, troubleshooting and requirements.

## Threat model

The backend is a paid vision-model proxy. If anyone can call it, the deployment pays for
their requests and the app's quota can be drained. The browser page is public, so the
protection has to work without a secret inside the page source.

## Authentication

`vlm-process.php` accepts **either** of these, and rejects the request when neither is
valid (`401 unauthorized`):

| Credential | Header | Used by |
| --- | --- | --- |
| Long-lived API key | `X-API-Key: <APP_API_KEY>` or `Authorization: Bearer <APP_API_KEY>` | CLI tools, scripts, API clients |
| Short-lived signed token | `X-VLM-Token: <token>` | The web page (issued by `token.php`) |

Same-origin requests are **not** accepted on their own any more; a credential is always
required.

### Signed browser tokens

- `index.php` embeds a token in the page (`window.FC_TOKEN`) so the first request needs
  no extra round trip.
- `token.php` issues fresh tokens. It only answers **same-origin** requests:
  `Sec-Fetch-Site: same-origin`, or an `Origin`/`Referer` header whose host matches the
  request host. Anything else gets `403 origin_not_allowed`.
- The token format is `base64url(issued_at.expires_at.hmac_sha256)` signed with
  `APP_TOKEN_SECRET`.
- The signature covers the issue and expiry timestamps, so neither can be altered.
- The server rejects a token that is expired, that was issued in the future (clock-skew
  guard), or whose signature does not match.
- `APP_TOKEN_SECRET` should be **different** from `APP_API_KEY`, so a leaked API key
  cannot be used to forge tokens. It lives only in `config.secrets.php`.
- Default lifetime is `APP_TOKEN_TTL` = 300 seconds. The leaked value of a token is
  therefore only useful for a few minutes.

### Idle tabs and token expiry

A token expiring while the user reads the page is the main practical problem, so the
frontend never relies on a single long-lived token:

| Trigger | Behaviour |
| --- | --- |
| Before every analysis | `ensureToken()` refreshes when less than `APP_TOKEN_MIN_REFRESH` seconds remain. |
| `visibilitychange` (tab shown again) | Refresh immediately. |
| `focus`, `pageshow`, `online` | Refresh immediately. |
| Every 4 minutes | Background refresh while the page is visible. |
| `401` from the API | Refresh once and retry the same request automatically. |

The token is kept in a JavaScript variable only. It is never written to `localStorage`,
`sessionStorage` or a cookie, so it disappears when the tab is closed and is not shared
between browser profiles.

### Rotating secrets

- Change `APP_TOKEN_SECRET` and every outstanding token becomes invalid at once.
- Change `APP_API_KEY` and every API client must be updated.
- Both are read from `config.secrets.php` on each request, so a change takes effect
  immediately (no restart needed).

## CORS

- `APP_ALLOWED_ORIGINS` is empty by default, so no cross-origin browser request is
  answered with CORS headers.
- `*` allows any origin; a comma-separated list allows only those origins.
- The token endpoint additionally requires a same-origin request, so cross-origin pages
  cannot obtain a token even when CORS is wide open.

## Rate limiting

| Limit | Scope | Default | Setting |
| --- | --- | --- | --- |
| Per IP | Single client | 60 requests / 60 s | `APP_RATE_LIMIT`, `APP_RATE_WINDOW` |
| Global | All clients | 1 image analysis every 2 s | `APP_GLOBAL_MIN_INTERVAL` |

- The global limit uses an atomic Memcached `add()`, so it holds even with several PHP
  workers behind the proxy.
- Excess requests receive `429 rate_limited` with a `Retry-After` header.
- If Memcached is unavailable the same checks run against a file cache in
  `/tmp/food-check-cache`; if the cache cannot be reached the check fails open rather than
  blocking the app.

## Circuit breaker

- When the primary model fails (network error, HTTP error, timeout, empty response or an
  image rejection), the backend writes `vlm:primary:failed:<hash>` to Memcached with a TTL
  of `VLM_FAILURE_TTL` seconds (default 300).
- While the marker exists, requests skip the primary and use the fallback immediately. The
  response reports `primary_skipped: true`.
- A successful primary request deletes the marker, so recovery is automatic.
- The marker is scoped to the primary base URL and model.

## Log and image privacy

- Logs and uploaded images are stored in `APP_LOG_DIR` (default `/var/tmp/food-check`),
  **outside the public web root**.
- Directories are created with `0770` and files with `0640`; they are not web-readable.
- Only the processed image is saved (resized, EXIF stripped), never the original upload
  with its camera metadata.
- Set `APP_LOG_IMAGES=0` to store no images at all.
- API keys and the base64 image data are never written to the log.
- Source names are sanitized before they are logged; generated image filenames are random.
- The log viewer (`/log/`) needs the API key and only shows the last 30 entries; the file
  itself keeps 5000.
- The viewer stores the key in `sessionStorage` only, never in the page source.

## File protection

- `config.secrets.php` is a PHP file with a direct-access guard; requesting it returns
  `404`. It is also listed in `.gitignore`, so it is never committed.
- `config.php` and `lib.php` have the same guard.
- The old `.env` was removed from the web root. Use `FOOD_CHECK_ENV` pointing outside the
  document root if you prefer an env file.
- The repository `.git` directory is mode `700`, so nginx (running as `www-data`) cannot
  serve repository metadata.
- `.htaccess` denies dotfiles and `config.php` on Apache; nginx serves the site and
  ignores `.htaccess`.

## Upload safety

- Uploads are validated by MIME type (`finfo`), byte size and pixel dimensions.
- Remote image URLs are not accepted, so the backend cannot be used as a request
  forger (no SSRF).
- Images are decoded with GD and re-encoded, which removes metadata and any embedded
  payload.
- Only JPEG, PNG and WebP are accepted.

## Deployment checklist

- [ ] `APP_API_KEY` set to a long random value (`openssl rand -hex 32`).
- [ ] `APP_TOKEN_SECRET` set to a **different** long random value.
- [ ] `config.secrets.php` is mode `640` and owned by the PHP user.
- [ ] `config.secrets.php` is in `.gitignore` and not committed.
- [ ] `APP_LOG_DIR` is outside the document root and writable by the PHP user.
- [ ] The site is served over HTTPS (camera and signed tokens need a secure context).
- [ ] Memcached is bound to localhost and not reachable from the internet.
- [ ] `APP_ALLOWED_ORIGINS` is empty unless cross-origin access is really needed.
- [ ] `APP_GLOBAL_MIN_INTERVAL` and `APP_RATE_LIMIT` are tuned for the expected traffic.
- [ ] Log files are rotated or monitored so the disk cannot fill up.
