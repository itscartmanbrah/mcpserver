<?php
declare(strict_types=1);

/**
 * AI endpoint auth gate.
 *
 * Requires header: Authorization: Bearer <AI_API_KEY>
 * AI_API_KEY is loaded from .env by Bootstrap.php.
 */
$expected = (string)(getenv('AI_API_KEY') ?: '');

if ($expected === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'AI_API_KEY missing from environment'], JSON_UNESCAPED_SLASHES);
    exit;
}

$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($auth === '') {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Missing Authorization header'], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Invalid Authorization header format'], JSON_UNESCAPED_SLASHES);
    exit;
}

$token = trim($m[1]);
if ($token === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_SLASHES);
    exit;
}

