<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

try {
    $days = 7;
    if (isset($_GET['days'])) $days = (int)$_GET['days'];
    if ($days < 1) $days = 1;
    if ($days > 365) $days = 365;

    $limit = ai_limit(50);

    [$startLocal, $endLocal, $startUtc, $endUtc, $tzName] = ai_window_utc(
        (new DateTimeImmutable('now', new DateTimeZone(getenv('REPORT_TZ') ?: 'Australia/Melbourne')))->modify('-' . ($days - 1) . ' days')->format('Y-m-d'),
        (new DateTimeImmutable('now', new DateTimeZone(getenv('REPORT_TZ') ?: 'Australia/Melbourne')))->format('Y-m-d')
    );

    $utc = new DateTimeZone('UTC');
    $startDayUtc = (new DateTimeImmutable($startUtc, $utc))->format('Y-m-d');
    $endDayUtcExcl = (new DateTimeImmutable($endUtc, $utc))->format('Y-m-d');

    $pdo = ai_db();

    $sql = "
      SELECT
        ai.SKU AS sku,
        ai.Description AS description,
        ai.TotalAvailQOH AS current_qoh,
        COALESCE(ai.RetailPrice, ai.CurrentPrice, ai.Price) AS retail_price,
        ai.BrandID AS brand_id,
        ai.CategoryID AS category_id,
        ai.VendorID AS vendor_id,
        COALESCE(m.units_sold, 0) AS units_sold,
        COALESCE(m.events_count, 0) AS events_count,
        COALESCE(m.value, 0) AS value
      FROM eweb_active_items ai
      JOIN (
        SELECT
          md.sku AS sku,
          SUM(md.neg_abs_sum) AS units_sold,
          SUM(md.events_count) AS events_count,
          SUM(md.neg_abs_sum * COALESCE(ai2.RetailPrice, ai2.CurrentPrice, ai2.Price, 0)) AS value
        FROM eweb_inventory_movement_daily md
        JOIN eweb_active_items ai2
          ON " . ai_sku_join_on("ai2.SKU", "md.sku") . "
        WHERE md.day >= :startDay
          AND md.day <  :endDayExcl
          AND ai2.is_deleted = 0
          AND ai2.TotalAvailQOH IS NOT NULL
          AND ai2.TotalAvailQOH > 0
        GROUP BY md.sku
      ) m
        ON " . ai_sku_join_on("ai.SKU", "m.sku") . "
      WHERE ai.is_deleted = 0
        AND ai.TotalAvailQOH IS NOT NULL
        AND ai.TotalAvailQOH > 0
      ORDER BY units_sold DESC
      LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':startDay' => $startDayUtc, ':endDayExcl' => $endDayUtcExcl]);
    $rows = $stmt->fetchAll();

    $items = [];
    $totalUnits = 0.0;
    $totalValue = 0.0;

    foreach ($rows as $r) {
        $units = (float)$r['units_sold'];
        $value = (float)$r['value'];
        $totalUnits += $units;
        $totalValue += $value;

        $desc = $r['description'] ?? null;
        $desc = is_string($desc) ? trim($desc) : null;
        if ($desc === '') $desc = null;

        $items[] = [
            'sku' => (string)$r['sku'],
            'units_sold' => $units,
            'value' => $value,
            'retail_price' => $r['retail_price'] !== null ? (float)$r['retail_price'] : null,
            'current_qoh' => $r['current_qoh'] !== null ? (float)$r['current_qoh'] : null,
            'events_count' => (int)$r['events_count'],
            'description' => $desc,
            'brand_id' => $r['brand_id'] !== null ? (string)$r['brand_id'] : null,
            'category_id' => $r['category_id'] !== null ? (int)$r['category_id'] : null,
            'vendor_id' => $r['vendor_id'] !== null ? (string)$r['vendor_id'] : null,
        ];
    }

    ai_json_out([
        'days' => $days,
        'timezone' => $tzName,
        'daily_table_window_utc_days' => ['start_day' => $startDayUtc, 'end_day_exclusive' => $endDayUtcExcl],
        'total_units_sold' => $totalUnits,
        'total_value' => $totalValue,
        'items' => $items,
        'note' => 'Fast movers are stocked items with highest inferred sales (SUM(neg_abs_sum)) over the period. Value uses COALESCE(RetailPrice, CurrentPrice, Price).',
    ], 200);

} catch (Throwable $e) {
    error_log('[fast-movers] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
