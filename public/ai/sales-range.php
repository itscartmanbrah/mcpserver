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

    $limit = ai_limit(50);
    $tsCol = ai_timestamp_col();

    [$startLocal, $endLocal, $startUtc, $endUtc, $tzName] = ai_window_utc($from, $to);

    $pdo = ai_db();

    $sql = "
      SELECT
        d.sku,
        SUM(ABS(d.delta)) AS units,
        COALESCE(ai.RetailPrice, ai.CurrentPrice, ai.Price) AS retail_price
      FROM eweb_inventory_deltas d FORCE INDEX (idx_delta_time_sku)
      LEFT JOIN eweb_active_items ai
        ON " . ai_sku_join_on("ai.SKU", "d.sku") . "
      WHERE d.delta < 0
        AND d.{$tsCol} >= :startUtc
        AND d.{$tsCol} <  :endUtc
      GROUP BY d.sku, retail_price
      ORDER BY units DESC
      LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':startUtc' => $startUtc, ':endUtc' => $endUtc]);
    $rows = $stmt->fetchAll();

    $items = [];
    $totalUnits = 0.0;
    $totalValue = 0.0;

    foreach ($rows as $r) {
        $units = (float)$r['units'];
        $price = $r['retail_price'] !== null ? (float)$r['retail_price'] : null;
        $value = $price !== null ? $units * $price : null;

        $totalUnits += $units;
        if ($value !== null) $totalValue += $value;

        $items[] = [
            'sku' => (string)$r['sku'],
            'units' => $units,
            'retail_price' => $price,
            'line_value' => $value,
        ];
    }

    ai_json_out([
        'from' => $startLocal->format('Y-m-d'),
        'to' => $endLocal->modify('-1 day')->format('Y-m-d'),
        'timezone' => $tzName,
        'window_utc' => ['start' => $startUtc, 'end' => $endUtc],
        'total_units' => $totalUnits,
        'total_value' => $totalValue,
        'sku_breakdown' => $items,
        'note' => 'Sales are inferred from inventory decreases (delta < 0). This may include adjustments, transfers, or stock corrections.',
    ], 200);

} catch (Throwable $e) {
    error_log('[sales-range] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
