<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

try {
    // Reuse changes endpoint behaviour by setting direction=down
    $_GET['direction'] = 'down';
    require __DIR__ . '/inventory-changes-today.php';
} catch (Throwable $e) {
    error_log('[inventory-decreases-today] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
