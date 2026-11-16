<?php
declare(strict_types=1);

/**
 * Example secret configuration. Copy to config.secrets.php and fill in:
 *
 *     cp config.secrets.example.php config.secrets.php
 *     chmod 640 config.secrets.php
 *
 * config.secrets.php is gitignored. Environment variables override it.
 */

if (isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

return [
    // Vision-language model service. The model must accept image input.
    'VLM_BASE_URL' => 'https://your-vlm-host.example.com/v1',
    'VLM_MODEL' => 'your-vision-model',
    'VLM_API_KEY' => 'sk_replace_me',

    // Shared key the browser sends to vlm-process.php (Settings ⚙).
    // Generate with: openssl rand -hex 32
    'APP_API_KEY' => 'fc_replace_with_a_long_random_value',

    // Extra CORS origins (comma-separated). Empty = same-origin only.
    'APP_ALLOWED_ORIGINS' => '',

    // Image preprocessing.
    'APP_MAX_DIMENSION' => '1000',
    'APP_JPEG_QUALITY' => '85',

    // Per-IP rate limit (0 disables), window in seconds.
    'APP_RATE_LIMIT' => '60',
    'APP_RATE_WINDOW' => '60',

    // Model tuning.
    'VLM_TIMEOUT' => '90',
    'VLM_MAX_TOKENS' => '1200',
    'VLM_TEMPERATURE' => '0.2',
];
