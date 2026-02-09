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
        ai.VendorID AS vendor_id,
        SUM(ABS(d.delta)) AS units,
        SUM(ABS(d.delta) * COALESCE(ai.RetailPrice, ai.CurrentPrice, ai.Price, 0)) AS value
      FROM eweb_inventory_deltas d FORCE INDEX (idx_delta_time_sku)
      JOIN eweb_active_items ai
        ON " . ai_sku_join_on("ai.SKU", "d.sku") . "
      WHERE d.delta < 0
        AND d.{$tsCol} >= :startUtc
        AND d.{$tsCol} <  :endUtc
        AND ai.is_deleted = 0
      GROUP BY ai.VendorID
      ORDER BY units DESC
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
            'vendor_id' => $r['vendor_id'] !== null ? (string)$r['vendor_id'] : null,
            'units' => (float)$r['units'],
            'value' => (float)$r['value'],
        ], $rows),
        'note' => 'Sales are inferred from inventory decreases (delta < 0). Value uses COALESCE(RetailPrice, CurrentPrice, Price).',
    ], 200);

} catch (Throwable $e) {
    error_log('[sales-by-vendor] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
