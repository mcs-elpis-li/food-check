<?php
declare(strict_types=1);

/**
 * Shared helpers for the Food Check backend.
 *
 * Loaded by index.php, token.php, log/api.php and vlm-process.php.
 * Contains the JSON helpers, the error class, the Memcached/file cache used by
 * the rate limiter and the circuit breaker, the image/log helpers and the
 * signed-token helpers used to authorise browser requests.
 */

if (isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}


final class FoodCheckApiError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}


/**
 * @param array<string, mixed> $payload
 */
function fc_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


function fc_fail(int $status, string $code, string $message): never
{
    fc_json($status, [
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ]);
}


/**
 * Host used for same-origin checks, with any port removed.
 */
function fc_request_host(): string
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));

    return preg_replace('/:\d+$/', '', $host) ?? $host;
}


function fc_url_host(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }

    return strtolower((string) $parts['host']);
}


/**
 * True for a request made by the page itself. Browsers send
 * Sec-Fetch-Site: same-origin for these requests.
 */
function fc_is_same_origin(): bool
{
    $site = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($site === 'same-origin') {
        return true;
    }
    if ($site !== '' && $site !== 'none') {
        return false;
    }

    $host = fc_request_host();
    if ($host === '') {
        return false;
    }

    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $key) {
        $value = trim((string) ($_SERVER[$key] ?? ''));
        if ($value !== '' && fc_url_host($value) === $host) {
            return true;
        }
    }

    return false;
}


/**
 * Client IP, taking the reverse proxy into account.
 */
function fc_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // The public nginx (HTTPS) proxies to 127.0.0.1, so the forwarded
    // address is only trusted when the direct peer is loopback.
    if ($ip === '127.0.0.1' || $ip === '::1') {
        $forwarded = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        if (!filter_var($forwarded, FILTER_VALIDATE_IP)) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            $forwarded = trim($parts[0] ?? '');
        }
        if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
            $ip = $forwarded;
        }
    }

    return $ip;
}


function fc_cache_dir(): string
{
    $dir = sys_get_temp_dir() . '/food-check-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    return $dir;
}


function fc_cache_file(string $key): string
{
    return fc_cache_dir() . '/' . hash('sha256', $key) . '.json';
}


function fc_cache_file_read(string $key): mixed
{
    $file = fc_cache_file($key);
    $raw = @file_get_contents($file);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || (int) ($data['expires'] ?? 0) <= time()) {
        @unlink($file);

        return null;
    }

    return $data['value'] ?? null;
}


function fc_cache_file_write(string $key, mixed $value, int $ttl): bool
{
    $payload = json_encode(['expires' => time() + max(1, $ttl), 'value' => $value]);
    if ($payload === false) {
        return false;
    }

    return @file_put_contents(fc_cache_file($key), $payload, LOCK_EX) !== false;
}


/**
 * @return bool|null True when added, false when the key exists, null on I/O failure.
 */
function fc_cache_file_add(string $key, mixed $value, int $ttl): ?bool
{
    $file = fc_cache_file($key);
    $payload = json_encode(['expires' => time() + max(1, $ttl), 'value' => $value]);
    if ($payload === false) {
        return null;
    }

    $handle = @fopen($file, 'x');
    if ($handle !== false) {
        $written = fwrite($handle, $payload);
        fclose($handle);

        return $written !== false ? true : null;
    }

    $raw = @file_get_contents($file);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);
    if (is_array($data) && (int) ($data['expires'] ?? 0) > time()) {
        return false;
    }

    @unlink($file);
    $handle = @fopen($file, 'x');
    if ($handle === false) {
        return null;
    }
    $written = fwrite($handle, $payload);
    fclose($handle);

    return $written !== false ? true : null;
}


function fc_memcached(array $config): ?Memcached
{
    static $client = false;

    if ($client !== false) {
        return $client;
    }

    if (!class_exists('Memcached')) {
        return $client = null;
    }

    try {
        $client = new Memcached();
        $client->setOption(Memcached::OPT_PREFIX_KEY, (string) $config['memcached_prefix']);
        $client->addServer((string) $config['memcached_host'], (int) $config['memcached_port']);
        $client->getVersion();

        if ($client->getResultCode() !== Memcached::RES_SUCCESS) {
            return $client = null;
        }

        return $client;
    } catch (Throwable $error) {
        error_log('food-check: memcached init failed: ' . $error->getMessage());

        return $client = null;
    }
}


function fc_cache_get(array $config, string $key): mixed
{
    $client = fc_memcached($config);
    if ($client !== null) {
        $value = $client->get($key);
        $code = $client->getResultCode();
        if ($code === Memcached::RES_SUCCESS) {
            return $value;
        }
        if ($code === Memcached::RES_NOTFOUND) {
            return fc_cache_file_read($key);
        }
    }

    return fc_cache_file_read($key);
}


function fc_cache_set(array $config, string $key, mixed $value, int $ttl): void
{
    $ttl = max(1, $ttl);
    $client = fc_memcached($config);
    if ($client !== null && $client->set($key, $value, $ttl)) {
        return;
    }

    fc_cache_file_write($key, $value, $ttl);
}


function fc_cache_add(array $config, string $key, mixed $value, int $ttl): bool
{
    $ttl = max(1, $ttl);
    $client = fc_memcached($config);
    if ($client !== null) {
        if ($client->add($key, $value, $ttl)) {
            return true;
        }

        $code = $client->getResultCode();
        if ($code === Memcached::RES_NOTSTORED || $code === Memcached::RES_DATA_EXISTS) {
            return false;
        }
    }

    $result = fc_cache_file_add($key, $value, $ttl);

    // Fail open when the cache is unavailable: do not block the application.
    return $result === null ? true : $result;
}


function fc_cache_delete(array $config, string $key): void
{
    $client = fc_memcached($config);
    if ($client !== null) {
        $client->delete($key);
    }

    @unlink(fc_cache_file($key));
}


/**
 * Writable log directory, preferring APP_LOG_DIR and falling back to temp.
 */
function fc_log_dir(array $config): string
{
    $candidates = [(string) $config['log_dir'], sys_get_temp_dir() . '/food-check'];

    foreach ($candidates as $candidate) {
        $dir = rtrim(trim($candidate), '/');
        if ($dir === '') {
            continue;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            return $dir;
        }
    }

    return '';
}


/**
 * Prompt text without the base64 image payload.
 *
 * @param list<array<string, mixed>> $messages
 */
function fc_prompt_text(array $messages): string
{
    $parts = [];

    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }

        $role = (string) ($message['role'] ?? 'unknown');
        $content = $message['content'] ?? '';

        if (is_string($content)) {
            $parts[] = $role . ': ' . $content;
        } elseif (is_array($content)) {
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text') {
                    $parts[] = $role . ': ' . (string) ($part['text'] ?? '');
                }
            }
        }
    }

    $text = implode("\n", $parts);
    if (strlen($text) > 12000) {
        $text = function_exists('mb_substr')
            ? mb_substr($text, 0, 12000, 'UTF-8')
            : substr($text, 0, 12000);
        $text .= '...';
    }

    return $text;
}


/**
 * Save the processed image outside the public web root.
 */
function fc_save_image(array $config, string $bytes): ?string
{
    if (empty($config['log_images'])) {
        return null;
    }

    $dir = fc_log_dir($config);
    if ($dir === '') {
        return null;
    }

    $uploads = $dir . '/uploads';
    if (!is_dir($uploads)) {
        @mkdir($uploads, 0770, true);
    }
    if (!is_dir($uploads) || !is_writable($uploads)) {
        return null;
    }

    try {
        $suffix = bin2hex(random_bytes(6));
    } catch (Throwable $error) {
        $suffix = (string) mt_rand(100000, 999999);
    }

    $path = $uploads . '/' . gmdate('Ymd_His') . '_' . $suffix . '.jpg';
    if (@file_put_contents($path, $bytes, LOCK_EX) === false) {
        return null;
    }
    @chmod($path, 0640);

    return $path;
}


/**
 * Append one JSON line to vlm.log, keeping only the last APP_LOG_MAX_LINES.
 *
 * @param array<string, mixed> $entry
 */
function fc_log_call(array $config, array $entry): void
{
    try {
        $dir = fc_log_dir($config);
        if ($dir === '') {
            return;
        }

        $file = $dir . '/vlm.log';
        $line = json_encode(
            $entry,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($line === false) {
            return;
        }
        $line .= "\n";

        $maxLines = max(100, (int) $config['log_max_lines']);
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            return;
        }

        $countFile = $file . '.count';
        $count = (int) @file_get_contents($countFile);
        if ($count <= 0) {
            rewind($handle);
            $count = 0;
            while (fgets($handle) !== false) {
                $count++;
            }
        }

        fseek($handle, 0, SEEK_END);
        fwrite($handle, $line);
        $count++;

        if ($count > $maxLines) {
            fflush($handle);
            rewind($handle);
            $lines = [];
            while (($current = fgets($handle)) !== false) {
                $lines[] = $current;
            }
            $lines = array_slice($lines, -$maxLines);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, implode('', $lines));
            $count = count($lines);
        }

        @file_put_contents($countFile, (string) $count, LOCK_EX);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        @chmod($file, 0640);
        @chmod($countFile, 0640);
    } catch (Throwable $error) {
        error_log('food-check: log write failed: ' . $error->getMessage());
    }
}


function fc_global_rate_limit(array $config): void
{
    $interval = (int) $config['global_min_interval'];
    if ($interval <= 0) {
        return;
    }

    if (!fc_cache_add($config, 'rate:global:image', time(), $interval)) {
        header('Retry-After: ' . $interval);
        fc_fail(
            429,
            'rate_limited',
            'Only one image can be analyzed every ' . $interval . ' seconds. Please wait and try again.'
        );
    }
}


/**
 * Stable per-client session ID used by the OpenCode Zen fallback.
 */
function fc_session_id(): string
{
    return 'food-check-' . substr(hash('sha256', 'food-check|' . fc_client_ip()), 0, 32);
}


/**
 * @param array<string, mixed> $config
 */
function fc_rate_limit(array $config, string $ip): void
{
    $limit = (int) $config['rate_limit'];
    if ($limit <= 0) {
        return;
    }

    $window = (int) $config['rate_window'];
    $dir = sys_get_temp_dir() . '/food-check-rate';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }

    $file = $dir . '/' . hash('sha256', $ip) . '.json';
    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        return;
    }

    $count = 0;
    $retryAfter = $window;

    if (flock($handle, LOCK_EX)) {
        $raw = stream_get_contents($handle);
        $state = json_decode($raw ?: '', true);
        $now = time();

        if (!is_array($state)
            || ($state['start'] ?? 0) < $now - $window
            || ($state['start'] ?? 0) > $now
        ) {
            $state = ['start' => $now, 'count' => 0];
        }

        $state['count'] = (int) ($state['count'] ?? 0) + 1;
        $count = $state['count'];
        $retryAfter = max(1, ((int) $state['start'] + $window) - $now);

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($state));
        fflush($handle);
        flock($handle, LOCK_UN);
    }

    fclose($handle);

    if ($count > $limit) {
        header('Retry-After: ' . $retryAfter);
        fc_fail(429, 'rate_limited', 'Too many requests. Please wait a moment and try again.');
    }
}



/**
 * The answer languages the app can request.
 *
 * `field` says which JSON field the model should fill:
 *   en   -> fill the _en fields, leave _zh empty
 *   zh   -> fill the _zh fields, leave _en empty
 *   both -> fill both fields with the same meaning
 *
 * @return array<string, array{label: string, label_zh: string, field: string, tts: string}>
 */
function fc_languages(): array
{
    return [
        'en' => [
            'label' => 'English',
            'label_zh' => '英文',
            'field' => 'en',
            'tts' => 'en-US',
        ],
        'zh' => [
            'label' => '繁體中文',
            'label_zh' => '繁體中文（香港）',
            'field' => 'zh',
            'tts' => 'zh-HK',
        ],
        'yue' => [
            'label' => '廣東話',
            'label_zh' => '粵語（書面繁體）',
            'field' => 'zh',
            'tts' => 'zh-HK',
        ],
        'zh_cn' => [
            'label' => '简体中文',
            'label_zh' => '簡體中文',
            'field' => 'zh',
            'tts' => 'zh-CN',
        ],
        'es' => [
            'label' => 'Español',
            'label_zh' => '西班牙文',
            'field' => 'en',
            'tts' => 'es-ES',
        ],
        'fr' => [
            'label' => 'Français',
            'label_zh' => '法文',
            'field' => 'en',
            'tts' => 'fr-FR',
        ],
        'de' => [
            'label' => 'Deutsch',
            'label_zh' => '德文',
            'field' => 'en',
            'tts' => 'de-DE',
        ],
        'ja' => [
            'label' => '日本語',
            'label_zh' => '日文',
            'field' => 'en',
            'tts' => 'ja-JP',
        ],
        'ko' => [
            'label' => '한국어',
            'label_zh' => '韓文',
            'field' => 'en',
            'tts' => 'ko-KR',
        ],
        'it' => [
            'label' => 'Italiano',
            'label_zh' => '意大利文',
            'field' => 'en',
            'tts' => 'it-IT',
        ],
        'pt' => [
            'label' => 'Português',
            'label_zh' => '葡萄牙文',
            'field' => 'en',
            'tts' => 'pt-PT',
        ],
        'nl' => [
            'label' => 'Nederlands',
            'label_zh' => '荷蘭文',
            'field' => 'en',
            'tts' => 'nl-NL',
        ],
        'ru' => [
            'label' => 'Русский',
            'label_zh' => '俄文',
            'field' => 'en',
            'tts' => 'ru-RU',
        ],
        'ar' => [
            'label' => 'العربية',
            'label_zh' => '阿拉伯文',
            'field' => 'en',
            'tts' => 'ar-SA',
        ],
        'hi' => [
            'label' => 'हिन्दी',
            'label_zh' => '印地語',
            'field' => 'en',
            'tts' => 'hi-IN',
        ],
        'th' => [
            'label' => 'ไทย',
            'label_zh' => '泰語',
            'field' => 'en',
            'tts' => 'th-TH',
        ],
        'vi' => [
            'label' => 'Tiếng Việt',
            'label_zh' => '越南語',
            'field' => 'en',
            'tts' => 'vi-VN',
        ],
        'id' => [
            'label' => 'Bahasa Indonesia',
            'label_zh' => '印尼語',
            'field' => 'en',
            'tts' => 'id-ID',
        ],
        'ms' => [
            'label' => 'Bahasa Melayu',
            'label_zh' => '馬來語',
            'field' => 'en',
            'tts' => 'ms-MY',
        ],
        'tl' => [
            'label' => 'Tagalog',
            'label_zh' => '他加祿語',
            'field' => 'en',
            'tts' => 'tl-PH',
        ],
        'pl' => [
            'label' => 'Polski',
            'label_zh' => '波蘭語',
            'field' => 'en',
            'tts' => 'pl-PL',
        ],
        'tr' => [
            'label' => 'Türkçe',
            'label_zh' => '土耳其語',
            'field' => 'en',
            'tts' => 'tr-TR',
        ],
        'sv' => [
            'label' => 'Svenska',
            'label_zh' => '瑞典語',
            'field' => 'en',
            'tts' => 'sv-SE',
        ],
        'da' => [
            'label' => 'Dansk',
            'label_zh' => '丹麥語',
            'field' => 'en',
            'tts' => 'da-DK',
        ],
        'no' => [
            'label' => 'Norsk',
            'label_zh' => '挪威語',
            'field' => 'en',
            'tts' => 'nb-NO',
        ],
        'fi' => [
            'label' => 'Suomi',
            'label_zh' => '芬蘭語',
            'field' => 'en',
            'tts' => 'fi-FI',
        ],
        'cs' => [
            'label' => 'Čeština',
            'label_zh' => '捷克語',
            'field' => 'en',
            'tts' => 'cs-CZ',
        ],
        'sk' => [
            'label' => 'Slovenčina',
            'label_zh' => '斯洛伐克語',
            'field' => 'en',
            'tts' => 'sk-SK',
        ],
        'hu' => [
            'label' => 'Magyar',
            'label_zh' => '匈牙利語',
            'field' => 'en',
            'tts' => 'hu-HU',
        ],
        'ro' => [
            'label' => 'Română',
            'label_zh' => '羅馬尼亞語',
            'field' => 'en',
            'tts' => 'ro-RO',
        ],
        'el' => [
            'label' => 'Ελληνικά',
            'label_zh' => '希臘語',
            'field' => 'en',
            'tts' => 'el-GR',
        ],
        'he' => [
            'label' => 'עברית',
            'label_zh' => '希伯來語',
            'field' => 'en',
            'tts' => 'he-IL',
        ],
        'uk' => [
            'label' => 'Українська',
            'label_zh' => '烏克蘭語',
            'field' => 'en',
            'tts' => 'uk-UA',
        ],
        'bg' => [
            'label' => 'Български',
            'label_zh' => '保加利亞語',
            'field' => 'en',
            'tts' => 'bg-BG',
        ],
        'hr' => [
            'label' => 'Hrvatski',
            'label_zh' => '克羅地亞語',
            'field' => 'en',
            'tts' => 'hr-HR',
        ],
        'sr' => [
            'label' => 'Српски',
            'label_zh' => '塞爾維亞語',
            'field' => 'en',
            'tts' => 'sr-RS',
        ],
        'ca' => [
            'label' => 'Català',
            'label_zh' => '加泰隆尼亞語',
            'field' => 'en',
            'tts' => 'ca-ES',
        ],
        'eu' => [
            'label' => 'Euskara',
            'label_zh' => '巴斯克語',
            'field' => 'en',
            'tts' => 'eu-ES',
        ],
        'gl' => [
            'label' => 'Galego',
            'label_zh' => '加利西亞語',
            'field' => 'en',
            'tts' => 'gl-ES',
        ],
        'is' => [
            'label' => 'Íslenska',
            'label_zh' => '冰島語',
            'field' => 'en',
            'tts' => 'is-IS',
        ],
        'ga' => [
            'label' => 'Gaeilge',
            'label_zh' => '愛爾蘭語',
            'field' => 'en',
            'tts' => 'ga-IE',
        ],
        'cy' => [
            'label' => 'Cymraeg',
            'label_zh' => '威爾士語',
            'field' => 'en',
            'tts' => 'cy-GB',
        ],
        'et' => [
            'label' => 'Eesti',
            'label_zh' => '愛沙尼亞語',
            'field' => 'en',
            'tts' => 'et-EE',
        ],
        'lv' => [
            'label' => 'Latviešu',
            'label_zh' => '拉脫維亞語',
            'field' => 'en',
            'tts' => 'lv-LV',
        ],
        'lt' => [
            'label' => 'Lietuvių',
            'label_zh' => '立陶宛語',
            'field' => 'en',
            'tts' => 'lt-LT',
        ],
        'sq' => [
            'label' => 'Shqip',
            'label_zh' => '阿爾巴尼亞語',
            'field' => 'en',
            'tts' => 'sq-AL',
        ],
        'mk' => [
            'label' => 'Македонски',
            'label_zh' => '馬其頓語',
            'field' => 'en',
            'tts' => 'mk-MK',
        ],
        'bs' => [
            'label' => 'Bosanski',
            'label_zh' => '波斯尼亞語',
            'field' => 'en',
            'tts' => 'bs-BA',
        ],
        'be' => [
            'label' => 'Беларуская',
            'label_zh' => '白俄羅斯語',
            'field' => 'en',
            'tts' => 'be-BY',
        ],
        'kk' => [
            'label' => 'Қазақша',
            'label_zh' => '哈薩克語',
            'field' => 'en',
            'tts' => 'kk-KZ',
        ],
        'uz' => [
            'label' => 'Oʻzbekcha',
            'label_zh' => '烏茲別克語',
            'field' => 'en',
            'tts' => 'uz-UZ',
        ],
        'mn' => [
            'label' => 'Монгол',
            'label_zh' => '蒙古語',
            'field' => 'en',
            'tts' => 'mn-MN',
        ],
        'ka' => [
            'label' => 'ქართული',
            'label_zh' => '格魯吉亞語',
            'field' => 'en',
            'tts' => 'ka-GE',
        ],
        'hy' => [
            'label' => 'Հայերեն',
            'label_zh' => '亞美尼亞語',
            'field' => 'en',
            'tts' => 'hy-AM',
        ],
        'bn' => [
            'label' => 'বাংলা',
            'label_zh' => '孟加拉語',
            'field' => 'en',
            'tts' => 'bn-BD',
        ],
        'ur' => [
            'label' => 'اردو',
            'label_zh' => '烏爾都語',
            'field' => 'en',
            'tts' => 'ur-PK',
        ],
        'fa' => [
            'label' => 'فارسی',
            'label_zh' => '波斯語',
            'field' => 'en',
            'tts' => 'fa-IR',
        ],
        'ta' => [
            'label' => 'தமிழ்',
            'label_zh' => '泰米爾語',
            'field' => 'en',
            'tts' => 'ta-IN',
        ],
        'te' => [
            'label' => 'తెలుగు',
            'label_zh' => '泰盧固語',
            'field' => 'en',
            'tts' => 'te-IN',
        ],
        'ml' => [
            'label' => 'മലയാളം',
            'label_zh' => '馬拉雅拉姆語',
            'field' => 'en',
            'tts' => 'ml-IN',
        ],
        'mr' => [
            'label' => 'मराठी',
            'label_zh' => '馬拉地語',
            'field' => 'en',
            'tts' => 'mr-IN',
        ],
        'gu' => [
            'label' => 'ગુજરાતી',
            'label_zh' => '古吉拉特語',
            'field' => 'en',
            'tts' => 'gu-IN',
        ],
        'pa' => [
            'label' => 'ਪੰਜਾਬੀ',
            'label_zh' => '旁遮普語',
            'field' => 'en',
            'tts' => 'pa-IN',
        ],
        'ne' => [
            'label' => 'नेपाली',
            'label_zh' => '尼泊爾語',
            'field' => 'en',
            'tts' => 'ne-NP',
        ],
        'si' => [
            'label' => 'සිංහල',
            'label_zh' => '僧伽羅語',
            'field' => 'en',
            'tts' => 'si-LK',
        ],
        'km' => [
            'label' => 'ខ្មែរ',
            'label_zh' => '高棉語',
            'field' => 'en',
            'tts' => 'km-KH',
        ],
        'lo' => [
            'label' => 'ລາວ',
            'label_zh' => '老撾語',
            'field' => 'en',
            'tts' => 'lo-LA',
        ],
        'my' => [
            'label' => 'မြန်မာ',
            'label_zh' => '緬甸語',
            'field' => 'en',
            'tts' => 'my-MM',
        ],
        'sw' => [
            'label' => 'Kiswahili',
            'label_zh' => '斯瓦希里語',
            'field' => 'en',
            'tts' => 'sw-KE',
        ],
        'af' => [
            'label' => 'Afrikaans',
            'label_zh' => '南非荷蘭語',
            'field' => 'en',
            'tts' => 'af-ZA',
        ],
        'am' => [
            'label' => 'አማርኛ',
            'label_zh' => '阿姆哈拉語',
            'field' => 'en',
            'tts' => 'am-ET',
        ],
        'ha' => [
            'label' => 'Hausa',
            'label_zh' => '豪薩語',
            'field' => 'en',
            'tts' => 'ha-NG',
        ],
        'yo' => [
            'label' => 'Yorùbá',
            'label_zh' => '約魯巴語',
            'field' => 'en',
            'tts' => 'yo-NG',
        ],
        'ig' => [
            'label' => 'Igbo',
            'label_zh' => '伊博語',
            'field' => 'en',
            'tts' => 'ig-NG',
        ],
        'zu' => [
            'label' => 'isiZulu',
            'label_zh' => '祖魯語',
            'field' => 'en',
            'tts' => 'zu-ZA',
        ],
        'xh' => [
            'label' => 'isiXhosa',
            'label_zh' => '科薩語',
            'field' => 'en',
            'tts' => 'xh-ZA',
        ],
        'so' => [
            'label' => 'Soomaali',
            'label_zh' => '索馬利語',
            'field' => 'en',
            'tts' => 'so-SO',
        ],
        'eo' => [
            'label' => 'Esperanto',
            'label_zh' => '世界語',
            'field' => 'en',
            'tts' => 'eo-UN',
        ],
        'la' => [
            'label' => 'Latina',
            'label_zh' => '拉丁語',
            'field' => 'en',
            'tts' => 'la-VA',
        ],
        'mt' => [
            'label' => 'Malti',
            'label_zh' => '馬耳他語',
            'field' => 'en',
            'tts' => 'mt-MT',
        ],
        'mi' => [
            'label' => 'Māori',
            'label_zh' => '毛利語',
            'field' => 'en',
            'tts' => 'mi-NZ',
        ],
        'sm' => [
            'label' => 'Gagana Sāmoa',
            'label_zh' => '薩摩亞語',
            'field' => 'en',
            'tts' => 'sm-WS',
        ],
        'fil' => [
            'label' => 'Filipino',
            'label_zh' => '菲律賓語',
            'field' => 'en',
            'tts' => 'fil-PH',
        ],
        'ceb' => [
            'label' => 'Cebuano',
            'label_zh' => '宿務語',
            'field' => 'en',
            'tts' => 'ceb-PH',
        ],
        'jv' => [
            'label' => 'Basa Jawa',
            'label_zh' => '爪哇語',
            'field' => 'en',
            'tts' => 'jv-ID',
        ],
        'su' => [
            'label' => 'Basa Sunda',
            'label_zh' => '巽他語',
            'field' => 'en',
            'tts' => 'su-ID',
        ],
        'haw' => [
            'label' => 'ʻŌlelo Hawaiʻi',
            'label_zh' => '夏威夷語',
            'field' => 'en',
            'tts' => 'haw-US',
        ],
        'gd' => [
            'label' => 'Gàidhlig',
            'label_zh' => '蘇格蘭蓋爾語',
            'field' => 'en',
            'tts' => 'gd-GB',
        ],
        'br' => [
            'label' => 'Brezhoneg',
            'label_zh' => '布列塔尼語',
            'field' => 'en',
            'tts' => 'br-FR',
        ],
        'oc' => [
            'label' => 'Occitan',
            'label_zh' => '奧克語',
            'field' => 'en',
            'tts' => 'oc-FR',
        ],
        'co' => [
            'label' => 'Corsu',
            'label_zh' => '科西嘉語',
            'field' => 'en',
            'tts' => 'co-FR',
        ],
        'lb' => [
            'label' => 'Lëtzebuergesch',
            'label_zh' => '盧森堡語',
            'field' => 'en',
            'tts' => 'lb-LU',
        ],
        'fy' => [
            'label' => 'Frysk',
            'label_zh' => '弗里西語',
            'field' => 'en',
            'tts' => 'fy-NL',
        ],
        'yi' => [
            'label' => 'ייִדיש',
            'label_zh' => '意第緒語',
            'field' => 'en',
            'tts' => 'yi',
        ],
        'both' => [
            'label' => 'Both 兩者',
            'label_zh' => '英文 + 繁體中文',
            'field' => 'both',
            'tts' => 'en-US',
        ],
    ];
}

/**
 * The long-term conditions the app can reason about.
 *
 * @return list<string>
 */
function fc_health_conditions(): array
{
    return [
        'diabetes', 'heart_disease', 'stroke', 'kidney_disease', 'liver_disease',
        'hypertension', 'high_cholesterol', 'gout',
    ];
}

/**
 * Normalise a list of condition keys coming from the client.
 *
 * @return list<string>
 */
function fc_condition_list(mixed $value): array
{
    if (is_string($value)) {
        $value = preg_split('/[,;]+/', $value) ?: [];
    }
    if (!is_array($value)) {
        return [];
    }

    $allowed = fc_health_conditions();
    $out = [];
    foreach ($value as $item) {
        if (!is_scalar($item)) {
            continue;
        }
        $key = strtolower(trim((string) $item));
        $key = preg_replace('/[^a-z]+/', '_', $key) ?? '';
        $key = trim($key, '_');
        if (in_array($key, $allowed, true) && !in_array($key, $out, true)) {
            $out[] = $key;
        }
    }

    return $out;
}

/**
 * Base64url decode that tolerates missing padding.
 */
function fc_base64url_decode(string $value): string|false
{
    $value = strtr($value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return base64_decode($value, true);
}

/**
 * Issue a short-lived signed token for the browser.
 *
 * @return array{token: string, issued_at: int, expires_at: int}
 */
function fc_token_issue(array $config): array
{
    $secret = fc_token_secret($config);
    if ($secret === '') {
        throw new FoodCheckApiError(500, 'server_misconfigured', 'APP_TOKEN_SECRET is not configured.');
    }

    $ttl = max(60, (int) $config['token_ttl']);
    $issuedAt = time();
    $expiresAt = $issuedAt + $ttl;
    $signature = hash_hmac('sha256', $issuedAt . '.' . $expiresAt, $secret);
    $token = rtrim(strtr(base64_encode($issuedAt . '.' . $expiresAt . '.' . $signature), '+/', '-_'), '=');

    return [
        'token' => $token,
        'issued_at' => $issuedAt,
        'expires_at' => $expiresAt,
    ];
}

/**
 * Verify a signed token. Returns the payload or null when it is not usable.
 *
 * @return array{issued_at: int, expires_at: int}|null
 */
function fc_token_verify(array $config, string $token): ?array
{
    $secret = fc_token_secret($config);
    if ($secret === '' || $token === '') {
        return null;
    }

    $decoded = fc_base64url_decode(trim($token));
    if ($decoded === false) {
        return null;
    }

    $parts = explode('.', $decoded);
    if (count($parts) !== 3) {
        return null;
    }

    [$issuedAt, $expiresAt, $signature] = $parts;
    if (!ctype_digit($issuedAt) || !ctype_digit($expiresAt) || !ctype_xdigit($signature)) {
        return null;
    }

    $issuedAt = (int) $issuedAt;
    $expiresAt = (int) $expiresAt;
    $now = time();

    if ($expiresAt <= $now || $issuedAt > $now + 60) {
        return null;
    }

    $expected = hash_hmac('sha256', $issuedAt . '.' . $expiresAt, $secret);
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    return ['issued_at' => $issuedAt, 'expires_at' => $expiresAt];
}

/**
 * The secret used to sign browser tokens.
 */
function fc_token_secret(array $config): string
{
    $secret = (string) $config['token_secret'];

    return $secret !== '' ? $secret : (string) $config['app_api_key'];
}

/**
 * The token sent by the browser, if any.
 */
function fc_request_token(): string
{
    return trim($_SERVER['HTTP_X_VLM_TOKEN'] ?? '');
}

/**
 * The API key sent by the browser or a CLI client, if any.
 */
function fc_request_api_key(): string
{
    $key = trim($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($key !== '') {
        return $key;
    }

    $authorization = trim($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

/**
 * Authorise a request: either the long-lived API key or a valid signed token.
 */
function fc_authorize(array $config, bool $allowToken = true): void
{
    $expectedKey = (string) $config['app_api_key'];
    $apiKey = fc_request_api_key();
    if ($expectedKey !== '' && $apiKey !== '' && hash_equals($expectedKey, $apiKey)) {
        return;
    }

    if ($allowToken) {
        $payload = fc_token_verify($config, fc_request_token());
        if ($payload !== null) {
            return;
        }
    }

    fc_fail(401, 'unauthorized', 'A valid API key or signed token is required.');
}

/**
 * Only browser requests from this site may request a token.
 */
function fc_require_same_origin(): void
{
    if (fc_is_same_origin()) {
        return;
    }

    fc_fail(403, 'origin_not_allowed', 'Tokens are only issued to same-origin requests.');
}
