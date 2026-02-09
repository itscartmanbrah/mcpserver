<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';
require __DIR__ . '/_auth.php';

use App\Support\Db;

try {
    $mode  = strtolower((string)($_GET['mode'] ?? 'changes')); // changes|decreases|sales
    $scope = strtolower((string)($_GET['scope'] ?? 'today'));  // today|hours

    $hours = (int)($_GET['hours'] ?? 4);
    if ($hours < 1) $hours = 1;
    if ($hours > 168) $hours = 168; // cap at 7 days

    $limit = (int)($_GET['limit'] ?? 200);
    if ($limit < 1) $limit = 1;
    if ($limit > 2000) $limit = 2000;

    $minAbsDelta = (string)($_GET['min_abs_delta'] ?? '0.0001');
    if (!is_numeric($minAbsDelta)) $minAbsDelta = '0';

    if (!in_array($mode, ['changes', 'decreases', 'sales'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid mode. Use changes|decreases|sales'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (!in_array($scope, ['today', 'hours'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid scope. Use today|hours'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $pdo = Db::pdo();

    // Time window based on sync_runs.started_at (server/MySQL time)
    if ($scope === 'today') {
        $timeSql = "sr.started_at >= CURDATE()";
        $bindHours = false;
        $windowLabel = 'today';
    } else {
        $timeSql = "sr.started_at >= (NOW() - INTERVAL ? HOUR)";
        $bindHours = true;
        $windowLabel = "last_{$hours}_hours";
    }

    // Aggregate per SKU across runs in window
    if ($mode === 'changes') {
        $having = "HAVING ABS(net_delta) >= ?";
        $orderBy = "ORDER BY ABS(net_delta) DESC";
        $disclaimer = null;
    } else {
        $having = "HAVING net_delta < 0 AND ABS(net_delta) >= ?";
        $orderBy = "ORDER BY net_delta ASC";
        $disclaimer = ($mode === 'sales')
            ? "“Sales” are inferred from net inventory decreases (QOH deltas). This may include non-sale adjustments."
            : null;
    }

    // LIMIT cannot be bound in MySQL with native prepares reliably; enforce numeric cap above and inline.
    $sql = "
        SELECT
            d.sku AS sku,
            SUM(d.delta) AS net_delta,
            COUNT(DISTINCT d.to_sync_run_id) AS runs_count,
            MIN(d.to_sync_run_id) AS first_to_run_id,
            MAX(d.to_sync_run_id) AS last_to_run_id,
            MIN(sr.started_at) AS first_run_started_at,
            MAX(sr.started_at) AS last_run_started_at
        FROM eweb_inventory_deltas d
        INNER JOIN sync_runs sr ON sr.id = d.to_sync_run_id
        WHERE
            sr.job_name = 'sync-active-items-latest'
            AND sr.status = 'success'
            AND {$timeSql}
        GROUP BY d.sku
        {$having}
        {$orderBy}
        LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);

    $params = [];
    if ($bindHours) {
        $params[] = $hours;
    }
    $params[] = (float)$minAbsDelta;

    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    echo json_encode([
        'ok' => true,
        'mode' => $mode,
        'scope' => $scope,
        'window' => $windowLabel,
        'limit' => $limit,
        'min_abs_delta' => (float)$minAbsDelta,
        'disclaimer' => $disclaimer,
        'count' => count($rows),
        'data' => array_map(static function (array $r): array {
            return [
                'sku' => (string)$r['sku'],
                'net_delta' => (float)$r['net_delta'],
                'runs_count' => (int)$r['runs_count'],
                'first_to_run_id' => (int)$r['first_to_run_id'],
                'last_to_run_id' => (int)$r['last_to_run_id'],
                'first_run_started_at' => (string)$r['first_run_started_at'],
                'last_run_started_at' => (string)$r['last_run_started_at'],
            ];
        }, $rows),
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
