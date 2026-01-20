<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';
require __DIR__ . '/_auth.php';

use App\Support\Db;

try {
    $scope = strtolower((string)($_GET['scope'] ?? 'today')); // today|hours
    $hours = (int)($_GET['hours'] ?? 4);
    if ($hours < 1) $hours = 1;
    if ($hours > 168) $hours = 168;

    $minAbsDelta = (string)($_GET['min_abs_delta'] ?? '0.0001');
    if (!is_numeric($minAbsDelta)) $minAbsDelta = '0.0001';
    $minAbsDeltaFloat = (float)$minAbsDelta;

    if (!in_array($scope, ['today', 'hours'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid scope. Use today|hours'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $pdo = Db::pdo();

    // Define time window based on sync_runs.started_at
    if ($scope === 'today') {
        $timeSql = "sr.started_at >= CURDATE()";
        $timeParamsRuns = [];
        $timeParamsDeltas = [];
        $windowLabel = 'today';
    } else {
        $timeSql = "sr.started_at >= (NOW() - INTERVAL ? HOUR)";
        $timeParamsRuns = [$hours];
        $timeParamsDeltas = [$hours];
        $windowLabel = "last_{$hours}_hours";
    }

    /* =========================================================
       Runs considered (all successful runs in window)
       ========================================================= */
    $sqlRuns = "
        SELECT
            COUNT(*) AS runs_considered_count,
            MIN(sr.id) AS first_to_run_id,
            MAX(sr.id) AS last_to_run_id,
            MIN(sr.started_at) AS first_run_started_at,
            MAX(sr.started_at) AS last_run_started_at
        FROM sync_runs sr
        WHERE
            sr.job_name = 'sync-active-items-latest'
            AND sr.status = 'success'
            AND {$timeSql}
    ";

    $stmt = $pdo->prepare($sqlRuns);
    $stmt->execute($timeParamsRuns);
    $runsRow = $stmt->fetch() ?: [];

    /* =========================================================
       Row-level summary (movement rows only)
       ========================================================= */
    $sql = "
        SELECT
            COUNT(*) AS total_rows,
            SUM(CASE WHEN d.delta < 0 THEN 1 ELSE 0 END) AS neg_rows,
            SUM(CASE WHEN d.delta > 0 THEN 1 ELSE 0 END) AS pos_rows,
            SUM(CASE WHEN d.delta = 0 THEN 1 ELSE 0 END) AS zero_rows,

            SUM(CASE WHEN d.delta < 0 THEN ABS(d.delta) ELSE 0 END) AS total_units_decreased,
            SUM(CASE WHEN d.delta > 0 THEN d.delta ELSE 0 END) AS total_units_increased,

            COUNT(DISTINCT d.to_sync_run_id) AS movement_runs_count
        FROM eweb_inventory_deltas d
        INNER JOIN sync_runs sr ON sr.id = d.to_sync_run_id
        WHERE
            sr.job_name = 'sync-active-items-latest'
            AND sr.status = 'success'
            AND {$timeSql}
            AND ABS(d.delta) >= ?
    ";

    $stmt = $pdo->prepare($sql);
    $params = array_merge($timeParamsDeltas, [$minAbsDeltaFloat]);
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];

    /* =========================================================
       SKU-level summary (net per SKU over the window)
       ========================================================= */
    $sqlSku = "
        SELECT
            COUNT(*) AS sku_count,
            SUM(CASE WHEN net_delta < 0 THEN 1 ELSE 0 END) AS skus_decreased,
            SUM(CASE WHEN net_delta > 0 THEN 1 ELSE 0 END) AS skus_increased,
            SUM(CASE WHEN net_delta = 0 THEN 1 ELSE 0 END) AS skus_no_change,

            SUM(CASE WHEN net_delta < 0 THEN ABS(net_delta) ELSE 0 END) AS net_units_decreased,
            SUM(CASE WHEN net_delta > 0 THEN net_delta ELSE 0 END) AS net_units_increased
        FROM (
            SELECT d.sku, SUM(d.delta) AS net_delta
            FROM eweb_inventory_deltas d
            INNER JOIN sync_runs sr ON sr.id = d.to_sync_run_id
            WHERE
                sr.job_name = 'sync-active-items-latest'
                AND sr.status = 'success'
                AND {$timeSql}
            GROUP BY d.sku
        ) x
        WHERE ABS(x.net_delta) >= ?
    ";

    $stmt = $pdo->prepare($sqlSku);
    $paramsSku = array_merge($timeParamsDeltas, [$minAbsDeltaFloat]);
    $stmt->execute($paramsSku);
    $skuRow = $stmt->fetch() ?: [];

    echo json_encode([
        'ok' => true,
        'scope' => $scope,
        'window' => $windowLabel,
        'min_abs_delta' => $minAbsDeltaFloat,

        'rows' => [
            'total_rows' => (int)($row['total_rows'] ?? 0),
            'neg_rows' => (int)($row['neg_rows'] ?? 0),
            'pos_rows' => (int)($row['pos_rows'] ?? 0),
            'zero_rows' => (int)($row['zero_rows'] ?? 0),
            'total_units_decreased' => (float)($row['total_units_decreased'] ?? 0),
            'total_units_increased' => (float)($row['total_units_increased'] ?? 0),
        ],

        'skus' => [
            'sku_count' => (int)($skuRow['sku_count'] ?? 0),
            'skus_decreased' => (int)($skuRow['skus_decreased'] ?? 0),
            'skus_increased' => (int)($skuRow['skus_increased'] ?? 0),
            'skus_no_change' => (int)($skuRow['skus_no_change'] ?? 0),
            'net_units_decreased' => (float)($skuRow['net_units_decreased'] ?? 0),
            'net_units_increased' => (float)($skuRow['net_units_increased'] ?? 0),
        ],

        'runs' => [
            'runs_considered_count' => (int)($runsRow['runs_considered_count'] ?? 0),
            'movement_runs_count' => (int)($row['movement_runs_count'] ?? 0),
            'first_to_run_id' => (int)($runsRow['first_to_run_id'] ?? 0),
            'last_to_run_id' => (int)($runsRow['last_to_run_id'] ?? 0),
            'first_run_started_at' => (string)($runsRow['first_run_started_at'] ?? ''),
            'last_run_started_at' => (string)($runsRow['last_run_started_at'] ?? ''),
        ],

        'disclaimer' => "“Sales” are inferred from inventory decreases (QOH deltas). This may include non-sale adjustments.",
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
