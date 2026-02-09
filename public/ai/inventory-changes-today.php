<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

try {
    $direction = isset($_GET['direction']) ? strtolower((string)$_GET['direction']) : 'all';
    if (!in_array($direction, ['all','up','down'], true)) $direction = 'all';

    $limit = ai_limit(100);
    $tsCol = ai_timestamp_col();

    $tzName = getenv('REPORT_TZ') ?: 'Australia/Melbourne';
    $tz = new DateTimeZone($tzName);
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

    [$startLocal, $endLocal, $startUtc, $endUtc, $tzName2] = ai_window_utc($today, $today);

    $pdo = ai_db();

    $whereDelta = '';
    if ($direction === 'up') $whereDelta = ' AND d.delta > 0';
    if ($direction === 'down') $whereDelta = ' AND d.delta < 0';

    $sql = "
      SELECT
        d.sku,
        d.from_qoh,
        d.to_qoh,
        d.delta,
        d.{$tsCol} AS ts_utc,
        COALESCE(ai.RetailPrice, ai.CurrentPrice, ai.Price) AS retail_price,
        ai.Description AS description
      FROM eweb_inventory_deltas d FORCE INDEX (idx_computed_at)
      LEFT JOIN eweb_active_items ai
        ON " . ai_sku_join_on("ai.SKU", "d.sku") . "
      WHERE d.{$tsCol} >= :startUtc
        AND d.{$tsCol} <  :endUtc
        {$whereDelta}
      ORDER BY d.{$tsCol} DESC
      LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':startUtc' => $startUtc, ':endUtc' => $endUtc]);
    $rows = $stmt->fetchAll();

    ai_json_out([
        'date' => $startLocal->format('Y-m-d'),
        'timezone' => $tzName2,
        'direction' => $direction,
        'window_utc' => ['start' => $startUtc, 'end' => $endUtc],
        'rows' => $rows,
    ], 200);

} catch (Throwable $e) {
    error_log('[inventory-changes-today] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
