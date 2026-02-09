<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

try {
    $sku = isset($_GET['sku']) ? trim((string)$_GET['sku']) : '';
    if ($sku === '') ai_json_out(['error' => 'Missing required parameter: sku'], 400);

    $from = isset($_GET['from']) ? (string)$_GET['from'] : '';
    $to   = isset($_GET['to']) ? (string)$_GET['to'] : '';
    if ($from === '' || $to === '') {
        ai_json_out(['error' => 'Missing required parameters: from, to (YYYY-MM-DD)'], 400);
    }

    $tsCol = ai_timestamp_col();
    [$startLocal, $endLocal, $startUtc, $endUtc, $tzName] = ai_window_utc($from, $to);

    $pdo = ai_db();

    $sql = "
      SELECT
        d.{$tsCol} AS ts_utc,
        d.from_qoh,
        d.to_qoh,
        d.delta
      FROM eweb_inventory_deltas d FORCE INDEX (idx_sku_time_delta)
      WHERE " . ai_sku_join_on("d.sku", ":sku") . "
        AND d.delta < 0
        AND d.{$tsCol} >= :startUtc
        AND d.{$tsCol} <  :endUtc
      ORDER BY d.{$tsCol} ASC
      LIMIT 2000
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':sku' => $sku, ':startUtc' => $startUtc, ':endUtc' => $endUtc]);
    $rows = $stmt->fetchAll();

    $totalUnits = 0.0;
    $events = [];
    foreach ($rows as $r) {
        $d = (float)$r['delta'];
        $u = abs($d);
        $totalUnits += $u;
        $events[] = [
            'ts_utc' => (string)$r['ts_utc'],
            'from_qoh' => (float)$r['from_qoh'],
            'to_qoh' => (float)$r['to_qoh'],
            'delta' => $d,
            'units' => $u,
        ];
    }

    ai_json_out([
        'sku' => $sku,
        'from' => $startLocal->format('Y-m-d'),
        'to' => $endLocal->modify('-1 day')->format('Y-m-d'),
        'timezone' => $tzName,
        'window_utc' => ['start' => $startUtc, 'end' => $endUtc],
        'total_units' => $totalUnits,
        'events' => $events,
        'note' => 'Sales are inferred from inventory decreases (delta < 0). This may include adjustments, transfers, or stock corrections.',
    ], 200);

} catch (Throwable $e) {
    error_log('[sales-sku] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
