<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

try {
    $from = isset($_GET['from']) ? (string)$_GET['from'] : '';
    $to   = isset($_GET['to']) ? (string)$_GET['to'] : '';
    if ($from === '' || $to === '') {
        ai_json_out(['error' => 'Missing required parameters: from, to (YYYY-MM-DD)'], 400);
    }

    $limit = ai_limit(100);

    // Convert the local-day window into UTC timestamps, then into UTC days for the daily table.
    [$startLocal, $endLocal, $startUtc, $endUtc, $tzName] = ai_window_utc($from, $to);

    $utc = new DateTimeZone('UTC');
    $startDayUtc = (new DateTimeImmutable($startUtc, $utc))->format('Y-m-d');
    $endDayUtcExcl = (new DateTimeImmutable($endUtc, $utc))->format('Y-m-d');

    $pdo = ai_db();

    $sql = "
      SELECT
        md.sku,
        SUM(md.delta_sum) AS net_delta,
        SUM(md.pos_sum)   AS increases,
        SUM(md.neg_abs_sum) AS decreases,
        SUM(md.events_count) AS events_count
      FROM eweb_inventory_movement_daily md
      WHERE md.day >= :startDay
        AND md.day <  :endDayExcl
      GROUP BY md.sku
      ORDER BY ABS(net_delta) DESC
      LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':startDay' => $startDayUtc, ':endDayExcl' => $endDayUtcExcl]);
    $rows = $stmt->fetchAll();

    ai_json_out([
        'from' => $startLocal->format('Y-m-d'),
        'to' => $endLocal->modify('-1 day')->format('Y-m-d'),
        'timezone' => $tzName,
        'window_utc' => ['start' => $startUtc, 'end' => $endUtc],
        'daily_table_window_utc_days' => ['start_day' => $startDayUtc, 'end_day_exclusive' => $endDayUtcExcl],
        'rows' => array_map(fn($r) => [
            'sku' => (string)$r['sku'],
            'net_delta' => (float)$r['net_delta'],
            'increases' => (float)$r['increases'],
            'decreases' => (float)$r['decreases'],
            'events_count' => (int)$r['events_count'],
        ], $rows),
        'note' => 'Net change is computed from daily aggregates (delta_sum, pos_sum, neg_abs_sum).',
    ], 200);

} catch (Throwable $e) {
    error_log('[inventory-net-change] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
