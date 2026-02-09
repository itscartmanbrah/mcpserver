<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

try {
    $from = isset($_GET['from']) ? (string)$_GET['from'] : '';
    $to   = isset($_GET['to']) ? (string)$_GET['to'] : '';
    if ($from === '' || $to === '') ai_json_out(['error' => 'Missing required parameters: from, to (YYYY-MM-DD)'], 400);

    $limit = ai_limit(50);
    $tsCol = ai_timestamp_col();

    [$startLocal, $endLocal, $startUtc, $endUtc, $tzName] = ai_window_utc($from, $to);

    $pdo = ai_db();

    $sql = "
      SELECT
        d.sku,
        SUM(d.delta) AS units_added,
        COALESCE(ai.RetailPrice, ai.CurrentPrice, ai.Price) AS retail_price
      FROM eweb_inventory_deltas d FORCE INDEX (idx_delta_time_sku)
      LEFT JOIN eweb_active_items ai
        ON " . ai_sku_join_on("ai.SKU", "d.sku") . "
      WHERE d.delta > 0
        AND d.{$tsCol} >= :startUtc
        AND d.{$tsCol} <  :endUtc
      GROUP BY d.sku, retail_price
      ORDER BY units_added DESC
      LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':startUtc' => $startUtc, ':endUtc' => $endUtc]);
    $rows = $stmt->fetchAll();

    ai_json_out([
        'from' => $startLocal->format('Y-m-d'),
        'to' => $endLocal->modify('-1 day')->format('Y-m-d'),
        'timezone' => $tzName,
        'window_utc' => ['start' => $startUtc, 'end' => $endUtc],
        'rows' => array_map(fn($r) => [
            'sku' => (string)$r['sku'],
            'units_added' => (float)$r['units_added'],
            'retail_price' => $r['retail_price'] !== null ? (float)$r['retail_price'] : null,
        ], $rows),
        'note' => 'New stock is inferred from inventory increases (delta > 0).',
    ], 200);

} catch (Throwable $e) {
    error_log('[new-stock] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
