# Food Check

A one-page camera app that checks whether a meal looks suitable for a selected IDDSI diet
level, aimed at elderly people with swallowing difficulty. The browser takes the photo, a
PHP backend resizes it and asks a vision-language model (VLM) to judge the food, and the
result is shown in plain language with a green / orange / red verdict.

Live site: **https://food-check.elpis.li/**

> Visual aid only. It cannot confirm ingredients or allergens. Always follow your doctor or
> dietitian/nutritionist.

## What the app does

- **IDDSI diet level selector** - Liquid / Pureed / Minced & Moist / Soft. The verdict is
  tailored to the selected level.
- **Rear-camera capture** - the camera preview is centered. Tap it or press **Check food**
  to capture. After a photo the camera stops and hides; press **Open camera** to take
  another.
- **Swallowing-safety analysis** - for every ingredient the model estimates the texture,
  the chewing/swallowing risk, and a verdict.
- **Red cross warning** - a big bright red cross appears when the photo is not food or is
  not edible.
- **Safer-food suggestions** - practical changes such as adding water, gravy or broth,
  steaming or re-cooking until fork-soft, mashing or blending, cutting smaller, and
  removing skin, bones, seeds or nuts.
- **Food types, hazards and allergens** - each ingredient is tagged with a type (fish,
  meat, pork, poultry, seafood, vegetable, fruit, grain, dairy, egg, legume, nut), with
  possible hazards (bones, fragments, seeds, skin, ...) and possible allergens.
- **Long-term health notes** - general, non-medical notes for diabetes, heart disease,
  stroke, kidney disease, liver disease, high blood pressure, high cholesterol and gout.
- **Bilingual output** - every result line is shown in English and Traditional Chinese.
- **Spoken results** - the browser can read the verdict, ingredient verdicts, food types,
  possible allergens and hazards, health notes and suggestions aloud, in English and
  Traditional Chinese, with **Read result** and **Stop** buttons.
- **Installable web app** - a manifest, icons and a service worker make it installable on
  Android and iOS. The installed app runs full screen without browser chrome, and a
  **full screen** button in the header hides the browser bars on the spot.
- **Settings panel** (⚙ **設定** in the header) - app language, long-term conditions,
  text size, theme, text colour, font type and speech, all changeable at any time.
- **Saved profile** - every setting is written to the device (localStorage, mirrored to a
  cookie) and restored on the next visit, with no flash of the wrong theme on load.
- **Elderly-friendly UI** - pink theme, 20px base text, 60px touch targets, high contrast,
  large result text and browser zoom enabled.

## How it works

```
Camera (rear, in-app)
   |  a frame is grabbed into a canvas and JPEG-encoded in the browser
   |  (upload cap 1920 px; the browser never sends more than that)
   v
index.php  -->  issues a short-lived signed token for the browser
   v
POST /vlm-process.php   { image: data URL, level, source }
   |  authorised with the signed token (X-VLM-Token) or the API key
   v
PHP backend (vlm-process.php)
   |  1. validate the upload (MIME, size, dimensions)
   |  2. read the EXIF orientation and rotate
   |  3. downscale to at most 1000 px on the longest side
   |  4. flatten transparency onto white, strip metadata, re-encode as JPEG
   |  5. build the prompt (diet level + food rules) and call the vision model
   v
Vision-language model (VLM)
   |  the model returns one JSON object describing the dish
   v
Backend normalises the JSON
   |  verdict, food types, hazards, allergens, health notes, suggestions
   v
Browser renders the bilingual result and reads it aloud
```

### The camera

The page uses `getUserMedia` with the rear camera (`facingMode: environment`) and an
ideal 1920x1920 stream. The live preview is shown in a centered 3:4 portrait box with a
**Tap to capture** hint. Tapping the preview, pressing Enter/Space while the preview has
focus, or pressing **Check food** all capture the current frame. The frame is drawn to a
canvas and encoded as JPEG in the browser, so the raw camera pixels never leave the device.
After the capture the stream is stopped, the preview is hidden and the photo appears in the
result card.

### The backend and the vision model

The backend is `vlm-process.php`. It performs the image work in PHP with GD (ImageMagick
and GraphicsMagick are installed, but GD is faster here and needs no external binary),
then posts an OpenAI-compatible chat completion to the configured vision model. The prompt
tells the model to:

- decide whether the photo shows food and whether it looks edible,
- classify each ingredient with one food-type key,
- list possible physical hazards and possible allergens for each ingredient,
- give general, non-diagnostic notes for common long-term conditions,
- suggest practical ways to make the food safer for the selected IDDSI level,
- answer with a single JSON object and Traditional Chinese for every Chinese string.

The model's JSON reply is normalised and validated on the server, so the browser always
receives the same shape.

### Why a backend at all

The browser never talks to the vision model directly. That means:

- the model credentials stay on the server and are never exposed in the page or in
  `localStorage`,
- there are no CORS problems, because the browser only calls the same origin,
- the image can be resized and stripped before it is sent, which keeps the request small
  and the answer consistent,
- a second model can take over automatically when the first one fails.

## Settings

The **⚙ 設定** button in the top-right corner opens a panel. Everything applies instantly and
is remembered on the device.

| Setting | Options |
| --- | --- |
| **App language 應用程式語言** | 95 choices: English, 繁體中文, 廣東話, 简体中文, Español, Français, Deutsch, 日本語, 한국어, Português, Nederlands, Русский, العربية, हिन्दी, ไทย, Tiếng Việt, Bahasa Indonesia, Bahasa Melayu, Tagalog, Polski, Türkçe, Svenska, Dansk, Norsk, Suomi, Čeština, Slovenčina, Magyar, Română, Ελληνικά, עברית, Українська, Български, Hrvatski, Српски, Català, Euskara, Galego, Íslenska, Gaeilge, Cymraeg, Eesti, Latviešu, Lietuvių, Shqip, Македонски, Bosanski, Беларуская, Қазақша, Oʻzbekcha, Монгол, ქართული, Հայերեն, বাংলা, اردو, فارسی, தமிழ், తెలుగు, മലയാളം, मराठी, ગુજરાતી, ਪੰਜਾਬੀ, नेपाली, සිංහල, ខ្មែរ, ລາວ, မြန်မာ, Kiswahili, Afrikaans, አማርኛ, Hausa, Yorùbá, Igbo, isiZulu, isiXhosa, Soomaali, Esperanto, Latina, Malti, Māori, Gagana Sāmoa, Filipino, Cebuano, Basa Jawa, Basa Sunda, ʻŌlelo Hawaiʻi, Gàidhlig, Brezhoneg, Occitan, Corsu, Lëtzebuergesch, Frysk, ייִדיש, and **Both 兩者**.
| **Long-term conditions 長期病患** | Multi-select: 糖尿病 Diabetes, 心臟病 Heart disease, 中風 Stroke, 腎病 Kidney disease, 肝病 Liver disease, 高血壓 High blood pressure, 高膽固醇 High cholesterol, 痛風 Gout. The analysis focuses on the conditions you picked. |
| **Text size 字體大小** | Extra small → Largest (6 steps, scales the whole interface). |
| **Theme 主題** | 14 themes, each with its own surface **and** accent colour: Pink, Rose, Blue, Green, Purple, Sunset, Teal, Slate, Sepia, Crimson, Navy, Dark, Midnight, High contrast. |
| **Text colour 字體顏色** | Default, Black, Grey, Brown, Navy, Green, Maroon. |
| **Font type 字型** | System, Rounded (self-hosted Nunito), Serif, Mono, Slab, Humanist. |
| **Speak results 語音朗讀** | On, Off, English only, Chinese only. |

The first choices of every group are shown directly; the rest sit behind a **More 更多**
button, which expands and collapses that group only.

### App language

One setting changes **the whole app**: the header, the ⚙ caption, the IDDSI level names,
the camera buttons, the tip, the result headings, the verdicts, food types, allergens,
health notes, ingredient details, status messages, the settings panel and the spoken answer.

| Chosen language | Interface, result and speech |
| --- | --- |
| **Both 兩者** | English and Traditional Chinese side by side, e.g. `Diet level (IDDSI) / 飲食級別 (IDDSI)` |
| **English** | English only |
| **繁體中文 / 廣東話** | Traditional Chinese only, e.g. `飲食級別 (IDDSI)` |
| **简体中文** | Simplified Chinese (`饮食级别`, `设置`) |
| **The other 90 languages** | The entire interface, the analysis result and the speech use that language |
| **العربية / עברית / فارسی / اردو / ייִדיש** | As above, with the layout switched to right-to-left |

The English and Traditional Chinese text is written by hand. The other languages are stored
in `ui-lang.js` and were produced with the local language model; they can be corrected there
without touching the app logic. Language names in the picker always stay in their own
language so people can find theirs.

### How the settings reach the model

Three settings are part of the request and change the prompt on every analysis:

| Setting | What it changes in the prompt |
| --- | --- |
| **Diet level (IDDSI)** | The person's level is written into the system message and the user message (`Soft (IDDSI 6-7: ...) / 軟食（...）`), and the safer-food suggestions are aimed at that level. |
| **App language** | Changes the whole interface, and a final `LANGUAGE:` instruction names the same language for the answer, saying which fields to fill (`_en` or `_zh`) and which to leave empty. `Both` asks for English and Traditional Chinese in every pair of fields. |
| **Long-term conditions** | The chosen conditions are listed in the system message and the user message. The model is told to write health notes **only** for those, and to make the safer-food advice relevant to them (salt, sugar, oil, purines, potassium, sodium, fluid). With none selected, the prompt explicitly forbids health notes and names no condition. |

- The prompt is rebuilt for every request; the exact text is stored in the `prompt` field of
  the log, so any change can be audited with `/log`.
- **Theme, text colour, font, text size and speech** are display and speech settings. They are
  never sent to the model and do not change the prompt by design.
- Single-language answers need the model's reasoning mode enabled, otherwise it answers in
  English. Reasoning runs before the JSON, so those requests get an 8,192-token output budget
  (the default 2,048 can be consumed entirely by reasoning and truncate the JSON).
- If no condition is selected, no health notes are returned at all; any note for a condition
  that was not selected is also discarded on the server, so the panel only ever shows the
  chosen ones.

### How the settings are stored

- The profile is saved to `localStorage` under `fc_profile_v1` and mirrored to a cookie, so
  it survives a refresh even when a browser blocks localStorage (private mode, strict
  cookie settings).
- A small script in the page head applies the saved theme, font, colour and text size
  **before the first paint**, so the page never flashes the default colours.
- Nothing about the profile leaves the device; it is not sent to the server except the
  language and conditions, which are part of the analysis request.

## What information it gives out

| Item | What it means |
| --- | --- |
| **Verdict** | Suitable / Caution / Not suitable for the chosen diet level, with a one-line summary in English and Traditional Chinese. |
| **Dish name** | What the model believes the dish is. |
| **Food types** | The kinds of food present: fish, meat, pork, poultry, seafood, vegetable, fruit, grain, dairy, egg, legume, nut or other. |
| **Ingredients** | Each visible ingredient with its texture (soft, firm, dry, crumbly, ...), its swallowing risk (hard pieces, skin, seeds, bones, sticky, ...) and its own verdict. |
| **Possible hazards** | Bones, small bones, fish bones, shell fragments, hard fragments, seeds, skin, cartilage, pits, spines, scales or nuts. |
| **Possible allergens** | Milk, egg, fish, shellfish, peanut, tree nuts, soy, wheat or sesame, when the photo makes them plausible. |
| **Safer-food suggestions** | Concrete changes to make the dish easier to swallow: add moisture, steam or re-cook, mash or puree, cut smaller, remove skin, bones, seeds or nuts, or thicken thin liquids. |
| **Long-term health notes** | General cautions for diabetes, heart disease, stroke, kidney disease, liver disease, high blood pressure, high cholesterol and gout, each marked Caution / Avoid / Unclear with a short reason. |
| **Red cross** | Shown instead of the normal result when the photo is not food or is not edible. |

Everything except the verdict is an estimate from a single photo. Ingredients and allergens
cannot be confirmed this way.

## Documentation

- **README-APP-STRUCTURE.md** - architecture, files, configuration, API reference,
  preprocessing steps, web app, logging and local development.
- **README-SECURITY.md** - authentication, signed tokens, CORS, rate limits, circuit
  breaker, logging privacy and the security checklist.
- **README-OTHERS.md** - model notes, verification results, troubleshooting and deployment
  requirements.
