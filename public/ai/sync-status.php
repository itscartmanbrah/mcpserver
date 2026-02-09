<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

try {
    $limit = ai_limit(20);
    $pdo = ai_db();

    $sql = "
      SELECT
        id,
        job_name,
        started_at,
        finished_at,
        status,
        message
      FROM sync_runs
      ORDER BY id DESC
      LIMIT {$limit}
    ";

    $runs = $pdo->query($sql)->fetchAll();

    $last = $runs[0] ?? null;

    $lastSuccess = null;
    foreach ($runs as $r) {
        if ((string)($r['status'] ?? '') === 'success') {
            $lastSuccess = $r;
            break;
        }
    }

    ai_json_out([
        'last_run' => $last,
        'last_success' => $lastSuccess,
        'runs' => $runs,
    ], 200);

} catch (Throwable $e) {
    error_log('[sync-status] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
