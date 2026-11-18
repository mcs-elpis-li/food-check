<?php
declare(strict_types=1);

/**
 * Issues short-lived signed tokens for the browser page.
 *
 * A token is only handed out to same-origin requests. The browser keeps it in
 * memory and sends it back as `X-VLM-Token` on every /vlm-process.php call.
 * Tokens expire after APP_TOKEN_TTL seconds, so a leaked token is only useful
 * for a few minutes.
 */

$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    fc_fail(405, 'method_not_allowed', 'Use POST.');
}

fc_require_same_origin();
fc_rate_limit($config, fc_client_ip());

try {
    $token = fc_token_issue($config);
} catch (FoodCheckApiError $error) {
    fc_fail($error->status, $error->errorCode, $error->getMessage());
}

fc_json(200, [
    'ok' => true,
    'token' => $token['token'],
    'issued_at' => $token['issued_at'],
    'expires_at' => $token['expires_at'],
    'ttl' => (int) $config['token_ttl'],
]);
