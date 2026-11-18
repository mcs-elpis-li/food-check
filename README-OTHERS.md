# Food Check - model notes, verification and troubleshooting

Everything that does not fit the product description (**README.md**) or the technical
reference (**README-APP-STRUCTURE.md**): model behaviour, verification results,
troubleshooting and deployment requirements.

- **README.md** - what the app does, how it works and what information it gives out.
- **README-APP-STRUCTURE.md** - architecture, files, configuration, API and logging.
- **README-SECURITY.md** - authentication, tokens, rate limits and privacy.

## Model notes

The configured primary vision model now accepts images. Test requests returned valid JSON
assessments with a 1000x750 resized image.

The primary provider limits output to **2048 tokens**. The backend therefore sets
`max_tokens` to 2048 and disables Qwen3 reasoning tokens with
`chat_template_kwargs: {"enable_thinking": false}` (`VLM_DISABLE_THINKING=1`). This keeps
the answer from being truncated while leaving the full budget for the JSON result. Set
`VLM_DISABLE_THINKING=0` for a model that does not support that option.

The configured fallback model is also verified as a vision model: it correctly identified
a red test image. The fallback provider requires an `x-opencode-session` header, so the
backend sends a stable per-client session ID for fallback requests. The fallback is tried
automatically when the primary fails, times out, or rejects images.

`deepseek-v4.1-flash` is also verified as a vision model. To use it, set
`VLM_FALLBACK_MODEL=deepseek-v4.1-flash`; no other change is needed.

If every provider fails or rejects images, the backend returns the last upstream error.

### Prompt rules

The backend asks the model for:

| Field | Values |
| --- | --- |
| `category` | `fish`, `meat`, `pork`, `poultry`, `seafood`, `vegetable`, `fruit`, `grain`, `dairy`, `egg`, `legume`, `nut`, `other` |
| `hazards` | `bones`, `small_bones`, `fish_bones`, `shell_fragments`, `hard_fragments`, `seeds`, `skin`, `cartilage`, `pits`, `spines`, `scales`, `nuts` |
| `allergens` | `milk`, `egg`, `fish`, `shellfish`, `peanut`, `tree_nut`, `soy`, `wheat`, `sesame` |
| `health_notes.condition` | `diabetes`, `heart_disease`, `stroke`, `kidney_disease`, `liver_disease`, `hypertension`, `high_cholesterol`, `gout` |
| `health_notes.level` | `caution`, `avoid`, `unknown` |

The server normalises these keys, maps common synonyms (`chicken` to `poultry`,
`kidney issues` to `kidney_disease`, ...) and drops anything unknown, so the UI always
receives a consistent set.

### Food analysis details

The backend normalises four extra layers of information. They are visual estimates, not
laboratory results.

**Food types.** Each item has one `category`. The UI shows chips for the dish's unique
`food_types` and a category under each ingredient.

**Possible hazards.** `hazards` lists possible physical hazards and the UI shows them as
orange chips. They are possibilities from the photo, not confirmed.

**Possible allergens.** Per-item lists are aggregated into the dish-level `allergens` and
shown in a prominent orange warning card. A photo cannot confirm ingredients; always check
the actual ingredients, sauces, marinades, cross-contact, and the person's allergy plan.

**Long-term health notes.** The model only returns conditions with a plausible concern
from the visible food, for example:

- **Diabetes:** refined carbohydrates, sweet sauces, large portions.
- **Heart disease / stroke / high blood pressure / high cholesterol:** salt, fried or fatty
  food, processed meat.
- **Kidney disease:** high-sodium or high-potassium foods, processed foods, dairy, nuts,
  beans.
- **Liver disease:** alcohol, high-fat or fried food, raw shellfish.
- **Gout:** red meat, organ meat, shellfish, beer, sugary drinks.

These are general ideas, not medical advice.

**Safety disclaimer.** A **doctor** manages medical conditions; a **dietitian /
nutritionist** advises on nutrition and long-term conditions. The app is not a substitute
for either. It cannot confirm ingredients or allergens from a photo. If the person has a
food allergy, check with the kitchen and follow the allergy plan. Health notes are general
information only, not a diagnosis or treatment plan.

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
- Signed tokens: issued tokens verify, tampered / expired / future-dated / wrong-secret
  tokens are all rejected, and the API answers `401` without a valid credential.
- PHP lint and JavaScript syntax checks pass.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `401 unauthorized` | Send a valid `X-VLM-Token` or `X-API-Key`. API clients should use the key; browsers get a token from `token.php`. |
| `403 origin_not_allowed` on `token.php` | Tokens are only issued to same-origin requests. |
| `vision_not_supported` | The model rejects images; set `VLM_MODEL` to a vision-capable model. |
| `upstream_unreachable` | Check `VLM_BASE_URL`, network access, and `VLM_API_KEY`. |
| `rate_limited` | Wait for `Retry-After`. The default global limit is one analysis every 2 seconds; adjust `APP_GLOBAL_MIN_INTERVAL` or the per-IP `APP_RATE_LIMIT`. |
| `primary_skipped: true` unexpectedly | The circuit breaker remembers a primary failure for `VLM_FAILURE_TTL` (300s). Wait for expiry, or delete the Memcached key. |
| Fallback is not used | Set `VLM_FALLBACK_BASE_URL`, `VLM_FALLBACK_MODEL`, and `VLM_FALLBACK_API_KEY`, and keep `VLM_FALLBACK_ENABLED=1`. |
| No `vlm.log` entry | Check that `APP_LOG_DIR` (default `/var/tmp/food-check`) is writable by the PHP user. Logs are not web-accessible. |
| `/log/` shows no entries | The viewer needs the API key; the log is empty until the first analysis of this deployment. |
| Memcached unavailable | The code falls back to `/tmp/food-check-cache`; check the Memcached service if you need shared state across PHP workers. |
| Camera does not start | Use HTTPS, allow camera permission, and check the browser console. |
| Upload exceeds the PHP limit | Multipart uploads use `.user.ini`; PHP-FPM applies it after up to 300s (reload PHP-FPM to apply immediately). The JSON path uses `post_max_size`. |

## Requirements

- PHP 8.1+ with `gd`, `curl`, `fileinfo`, `json`, and `exif` (all present here: PHP 8.5).
- PHP `memcached` extension and a Memcached server for the shared circuit breaker and
  global rate limit (optional; a file cache in `/tmp/food-check-cache` is used as fallback).
- PHP-FPM behind nginx/openresty.
- Permission for the PHP user to read `config.secrets.php`, write to the system temp
  directory (`/tmp`), and write to `APP_LOG_DIR` (default `/var/tmp/food-check`).
- `client_max_body_size` of at least 10 MB (already set globally); 30 MB on HTTPS.
- HTTPS for camera access in mobile browsers and for signed tokens (`getUserMedia` and
  `crypto.subtle` require a secure context).

## Image library choice

GraphicsMagick and ImageMagick are installed on this server, but the backend uses GD:

| Input | GD (current) | GraphicsMagick CLI | Imagick |
| --- | ---: | ---: | ---: |
| 960x870 (no resize) | 43-51 ms | 27-31 ms | 20 ms |
| 1600x1200 (2 MP) | 98-100 ms | 77-86 ms | 120 ms |
| 4032x3024 (12 MP) | 330-339 ms | 292-308 ms | 460 ms |
| 6000x5000 (30 MP) | 647-673 ms | 676-680 ms | 1092 ms |

GraphicsMagick is only ~10-25% faster for typical phone photos and roughly equal at
30 MP, which is a fraction of the 5-20 second model call. GD is simpler, has no external
dependency and already handles EXIF orientation, alpha flattening and metadata stripping.
Consider GraphicsMagick only if very large uploads are common or PHP memory needs to stay
isolated from image decoding.
