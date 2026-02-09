<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

try {
    $days = 30;
    if (isset($_GET['days'])) $days = (int)$_GET['days'];
    if ($days < 1) $days = 1;
    if ($days > 365) $days = 365;

    $limit = ai_limit(50);

    $tzName = getenv('REPORT_TZ') ?: 'Australia/Melbourne';
    $tz = new DateTimeZone($tzName);
    $utc = new DateTimeZone('UTC');

    $todayLocal = (new DateTimeImmutable('now', $tz))->setTime(0,0,0);
    $startLocal = $todayLocal->modify('-' . ($days - 1) . ' days');
    $endLocal   = $todayLocal->modify('+1 day');

    // We aggregate by DATE in UTC table, but we want local-day meaning.
    // Convert local window to UTC and use DATE(utc_timestamp) boundaries.
    $startUtc = $startLocal->setTimezone($utc)->format('Y-m-d H:i:s');
    $endUtc   = $endLocal->setTimezone($utc)->format('Y-m-d H:i:s');

    // Use dates in UTC for the daily table
    $startDayUtc = (new DateTimeImmutable($startUtc, $utc))->format('Y-m-d');
    $endDayUtcExcl = (new DateTimeImmutable($endUtc, $utc))->format('Y-m-d'); // exclusive upper day

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
        COALESCE(m.abs_sum, 0) AS movement_units,
        COALESCE(m.events_count, 0) AS events_count
      FROM eweb_active_items ai
      LEFT JOIN (
        SELECT
          md.sku AS sku,
          SUM(md.abs_sum) AS abs_sum,
          SUM(md.events_count) AS events_count
        FROM eweb_inventory_movement_daily md
        WHERE md.day >= :startDay
          AND md.day <  :endDayExcl
        GROUP BY md.sku
      ) m
        ON " . ai_sku_join_on("ai.SKU", "m.sku") . "
      WHERE ai.is_deleted = 0
        AND ai.TotalAvailQOH IS NOT NULL
        AND ai.TotalAvailQOH > 0
      ORDER BY movement_units ASC, ai.TotalAvailQOH DESC
      LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':startDay' => $startDayUtc,
        ':endDayExcl' => $endDayUtcExcl,
    ]);

    $rows = $stmt->fetchAll();

    $items = [];
    foreach ($rows as $r) {
        $desc = $r['description'] ?? null;
        $desc = is_string($desc) ? trim($desc) : null;
        if ($desc === '') $desc = null;

        $items[] = [
            'sku' => (string)$r['sku'],
            'movement_units' => (float)$r['movement_units'],
            'events_count' => (int)$r['events_count'],
            'current_qoh' => $r['current_qoh'] !== null ? (float)$r['current_qoh'] : null,
            'retail_price' => $r['retail_price'] !== null ? (float)$r['retail_price'] : null,
            'description' => $desc,
            'brand_id' => $r['brand_id'] !== null ? (string)$r['brand_id'] : null,
            'category_id' => $r['category_id'] !== null ? (int)$r['category_id'] : null,
            'vendor_id' => $r['vendor_id'] !== null ? (string)$r['vendor_id'] : null,
        ];
    }

    ai_json_out([
        'days' => $days,
        'timezone' => $tzName,
        'window_local' => [
            'start' => $startLocal->format('Y-m-d'),
            'end_exclusive' => $endLocal->format('Y-m-d'),
        ],
        'window_utc' => ['start' => $startUtc, 'end' => $endUtc],
        'daily_table_window_utc_days' => ['start_day' => $startDayUtc, 'end_day_exclusive' => $endDayUtcExcl],
        'count' => count($items),
        'items' => $items,
        'note' => 'Slow movers are stocked items (TotalAvailQOH > 0) with the lowest summed daily movement (SUM(abs_sum)) over the period.',
    ], 200);

} catch (Throwable $e) {
    error_log('[slow-movers] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
