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
