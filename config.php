<?php
declare(strict_types=1);

/**
 * Food Check backend configuration.
 *
 * Priority: real environment variables > config.secrets.php > optional
 * file named by FOOD_CHECK_ENV. Keep secrets out of the public document
 * root; config.secrets.php is a guarded PHP file for convenience here.
 *
 * This file returns an array and must never be requested directly.
 */

if (isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

/**
 * Minimal .env parser (KEY=VALUE, # comments, optional quotes).
 *
 * @return array<string, string>
 */
function foodcheck_read_env(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }

    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = ltrim(substr($line, 7));
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            $value = trim($value, $quote);
        }

        if ($key !== '') {
            $values[$key] = $value;
        }
    }

    return $values;
}

$env = [];

$secretsFile = __DIR__ . '/config.secrets.php';
if (is_readable($secretsFile)) {
    $secrets = require $secretsFile;
    if (is_array($secrets)) {
        $env = $secrets;
    }
}

// Optional external env file for deployments that prefer one. Point
// FOOD_CHECK_ENV at a path outside the document root.
$envFile = getenv('FOOD_CHECK_ENV');
if ($envFile !== false && $envFile !== '') {
    $env = array_merge($env, foodcheck_read_env($envFile));
}

$setting = static function (string $key, string $default = '') use ($env): string {
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    return $env[$key] ?? $default;
};

$origins = array_values(array_filter(
    array_map('trim', explode(',', $setting('APP_ALLOWED_ORIGINS', ''))),
    static fn (string $origin): bool => $origin !== ''
));

return [
    // Shared secret expected from the browser (X-API-Key / Bearer token).
    'app_api_key' => $setting('APP_API_KEY', ''),

    // Vision-language model service.
    'vlm_base_url' => rtrim($setting('VLM_BASE_URL', $setting('OPENAI_BASE_URL', '')), '/'),
    'vlm_model' => $setting('VLM_MODEL', $setting('OPENAI_MODEL', '')),
    'vlm_api_key' => $setting('VLM_API_KEY', $setting('OPENAI_API_KEY', '')),
    'vlm_timeout' => max(10, (int) $setting('VLM_TIMEOUT', '90')),

    // Image preprocessing.
    'max_dimension' => max(256, min(2048, (int) $setting('APP_MAX_DIMENSION', '1000'))),
    'jpeg_quality' => max(50, min(95, (int) $setting('APP_JPEG_QUALITY', '85'))),
    'max_image_bytes' => max(262144, (int) $setting('APP_MAX_IMAGE_BYTES', (string) (8 * 1024 * 1024))),
    'max_request_bytes' => max(524288, (int) $setting('APP_MAX_REQUEST_BYTES', (string) (12 * 1024 * 1024))),

    // Generation settings.
    'max_tokens' => max(100, min(2048, (int) $setting('VLM_MAX_TOKENS', '2048'))),
    'temperature' => max(0.0, min(2.0, (float) $setting('VLM_TEMPERATURE', '0.2'))),
    'disable_thinking' => in_array(
        strtolower($setting('VLM_DISABLE_THINKING', '1')),
        ['1', 'true', 'yes', 'on'],
        true
    ),

    // CORS: empty list means same-origin only. Use "*" to allow any origin.
    'allowed_origins' => $origins,

    // Let the browser page call the proxy without an API key. Non-browser
    // clients (and other origins) must still send X-API-Key.
    'allow_same_origin' => in_array(
        strtolower($setting('APP_ALLOW_SAME_ORIGIN', '1')),
        ['1', 'true', 'yes', 'on'],
        true
    ),

    // Simple per-IP rate limit (0 disables it).
    'rate_limit' => max(0, (int) $setting('APP_RATE_LIMIT', '60')),
    'rate_window' => max(10, (int) $setting('APP_RATE_WINDOW', '60')),
];
