<?php
declare(strict_types=1);

/**
 * Returns the last N analysis log entries as JSON.
 *
 * Requires the backend API key (X-API-Key or Authorization: Bearer). Used by
 * the /log viewer. The log file itself lives outside the web root.
 */

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../lib.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    fc_fail(405, 'method_not_allowed', 'Use GET.');
}

$apiKey = fc_request_api_key();
if ($apiKey === '' || !hash_equals((string) $config['app_api_key'], $apiKey)) {
    fc_fail(401, 'unauthorized', 'A valid API key is required.');
}

$limit = (int) ($_GET['limit'] ?? 30);
$limit = max(1, min(200, $limit));

$logFile = rtrim((string) $config['log_dir'], '/') . '/vlm.log';
$entries = [];

if (is_readable($logFile)) {
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        $lines = array_slice($lines, -$limit);
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }
    }
}

fc_json(200, [
    'ok' => true,
    'count' => count($entries),
    'entries' => $entries,
]);
