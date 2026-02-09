<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';
require __DIR__ . '/_auth.php'; // your AI_API_KEY Bearer auth (NOT OpenAI)

use App\Support\Env;

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function httpPostJson(string $url, array $payload, array $headers): array {
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Failed to init curl');

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge([
            'Content-Type: application/json',
        ], $headers),
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 60,
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL error: {$err}");
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($resp, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Non-JSON response from {$url} (HTTP {$status}): " . substr($resp, 0, 400));
    }

    return [$status, $decoded];
}

function findToolCalls(array $openaiResponse): array {
    // Responses API returns output[] items; tool calls appear as items of type "tool_call"
    $calls = [];
    $output = $openaiResponse['output'] ?? [];
    if (!is_array($output)) return $calls;

    foreach ($output as $item) {
        if (!is_array($item)) continue;
        if (($item['type'] ?? '') !== 'tool_call') continue;
        $calls[] = $item;
    }
    return $calls;
}

function extractAssistantText(array $openaiResponse): string {
    // Try to extract any assistant-facing text from output items
    $parts = [];
    $output = $openaiResponse['output'] ?? [];
    if (!is_array($output)) return '';

    foreach ($output as $item) {
        if (!is_array($item)) continue;
        if (($item['type'] ?? '') === 'output_text') {
            $parts[] = (string)($item['text'] ?? '');
        }
        // Some variants nest text in content arrays; this keeps it resilient.
        if (($item['type'] ?? '') === 'message' && isset($item['content']) && is_array($item['content'])) {
            foreach ($item['content'] as $c) {
                if (is_array($c) && ($c['type'] ?? '') === 'output_text') {
                    $parts[] = (string)($c['text'] ?? '');
                }
            }
        }
    }

    $text = trim(implode("\n", array_filter($parts, fn($x) => trim($x) !== '')));
    return $text;
}

/**
 * TOOL: get_sales_today
 * Safe bet: calls your existing endpoints and returns structured data only.
 */
function tool_get_sales_today(array $args): array {
    $scope = (string)($args['scope'] ?? 'today');   // today|hours
    $hours = (int)($args['hours'] ?? 4);
    $limit = (int)($args['limit'] ?? 20);
    $minAbs = (string)($args['min_abs_delta'] ?? '0.0001');

    if (!in_array($scope, ['today', 'hours'], true)) $scope = 'today';
    if ($hours < 1) $hours = 1;
    if ($hours > 168) $hours = 168;
    if ($limit < 1) $limit = 1;
    if ($limit > 200) $limit = 200;
    if (!is_numeric($minAbs)) $minAbs = '0.0001';

    // Internal call to your existing AI-safe JSON endpoints (loopback)
    // IMPORTANT: these endpoints already apply success-only run filters etc.
    $base = 'http://127.0.0.1';

    // Sales list (inferred decreases)
    $salesUrl = $base . '/ai/inventory-changes.php?mode=sales'
        . '&scope=' . rawurlencode($scope)
        . ($scope === 'hours' ? '&hours=' . $hours : '')
        . '&limit=' . $limit
        . '&min_abs_delta=' . rawurlencode($minAbs);

    // Summary
    $sumUrl = $base . '/ai/inventory-delta-summary.php?scope=' . rawurlencode($scope)
        . ($scope === 'hours' ? '&hours=' . $hours : '')
        . '&min_abs_delta=' . rawurlencode($minAbs);

    // Call them without needing Bearer because we're on localhost inside server context.
    // If your endpoints REQUIRE Bearer even on localhost, we can pass AI_API_KEY here too.
    $salesJson = json_decode((string)@file_get_contents($salesUrl), true);
    $sumJson = json_decode((string)@file_get_contents($sumUrl), true);

    if (!is_array($salesJson) || !is_array($sumJson)) {
        throw new RuntimeException('Failed to fetch internal endpoints for sales/summary');
    }

    return [
        'scope' => $scope,
        'hours' => $scope === 'hours' ? $hours : null,
        'min_abs_delta' => (float)$minAbs,
        'summary' => $sumJson,
        'sales' => $salesJson,
    ];
}

/* =========================
   MAIN
   ========================= */

$body = readJsonBody();
$userMessage = trim((string)($body['message'] ?? ''));

if ($userMessage === '') {
    jsonOut(['ok' => false, 'error' => 'Missing message'], 400);
}

$openaiKey = (string)(getenv('OPENAI_API_KEY') ?: '');
if ($openaiKey === '') {
    jsonOut(['ok' => false, 'error' => 'Missing OPENAI_API_KEY in environment'], 500);
}

$model = (string)(getenv('OPENAI_MODEL') ?: 'gpt-4o-mini');

$system = <<<SYS
You are an inventory assistant for a retail business.
- "Sales" are inferred from net inventory decreases (QOH deltas), not transactions.
- Use the provided tool to fetch authoritative data.
- If the user asks for "sales today", "what sold today", or similar, call get_sales_today with scope=today.
- Provide a concise, human-readable answer including totals and a short SKU list.
- Always include the disclaimer if you mention "sales".
SYS;

$tools = [
    [
        'type' => 'function',
        'name' => 'get_sales_today',
        'description' => 'Get inferred sales (inventory decreases) and summary for a time window.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'scope' => ['type' => 'string', 'enum' => ['today', 'hours']],
                'hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 168],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                'min_abs_delta' => ['type' => 'number', 'minimum' => 0],
            ],
            'required' => ['scope'],
            'additionalProperties' => false,
        ],
    ],
];

$req1 = [
    'model' => $model,
    'input' => [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $userMessage],
    ],
    'tools' => $tools,
];

try {
    // 1) Ask model (may return tool_call)
    [$status1, $resp1] = httpPostJson(
        'https://api.openai.com/v1/responses',
        $req1,
        ['Authorization: Bearer ' . $openaiKey]
    );

    if ($status1 < 200 || $status1 >= 300) {
        jsonOut(['ok' => false, 'error' => 'OpenAI request failed', 'details' => $resp1], 502);
    }

    $toolCalls = findToolCalls($resp1);

    // If no tool calls, return text directly
    if (count($toolCalls) === 0) {
        $text = extractAssistantText($resp1);
        jsonOut(['ok' => true, 'reply' => $text !== '' ? $text : '(no output)']);
    }

    // 2) Execute tool calls (POC expects at most one)
    $toolOutputs = [];
    foreach ($toolCalls as $call) {
        $toolName = (string)($call['name'] ?? '');
        $callId = (string)($call['call_id'] ?? '');

        $args = $call['arguments'] ?? [];
        if (is_string($args)) {
            $decodedArgs = json_decode($args, true);
            $args = is_array($decodedArgs) ? $decodedArgs : [];
        }

        if ($toolName === 'get_sales_today') {
            $result = tool_get_sales_today($args);
        } else {
            $result = ['error' => "Unknown tool: {$toolName}"];
        }

        $toolOutputs[] = [
            'type' => 'tool_output',
            'call_id' => $callId,
            'output' => json_encode($result, JSON_UNESCAPED_SLASHES),
        ];
    }

    // 3) Send tool output back to model for final answer
    $req2 = [
        'model' => $model,
        'input' => array_merge(
            $req1['input'],
            $toolOutputs
        ),
        'tools' => $tools,
    ];

    [$status2, $resp2] = httpPostJson(
        'https://api.openai.com/v1/responses',
        $req2,
        ['Authorization: Bearer ' . $openaiKey]
    );

    if ($status2 < 200 || $status2 >= 300) {
        jsonOut(['ok' => false, 'error' => 'OpenAI (final) request failed', 'details' => $resp2], 502);
    }

    $final = extractAssistantText($resp2);
    jsonOut(['ok' => true, 'reply' => $final !== '' ? $final : '(no output)']);

} catch (Throwable $e) {
    jsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
}
