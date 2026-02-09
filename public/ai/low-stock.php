<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

$tz = getenv('REPORT_TZ') ?: 'Australia/Melbourne';
date_default_timezone_set($tz);

try {
    $threshold = isset($_GET['threshold']) ? (int)$_GET['threshold'] : 2;
    if ($threshold < 1) $threshold = 1;
    if ($threshold > 1000) $threshold = 1000;

    $limit = ai_limit(200);

    $pdo = ai_db();

    $sql = "
      SELECT
        ai.SKU AS sku,
        ai.Description AS description,
        ai.BrandID AS brand_id,
        ai.CategoryID AS category_id,
        ai.VendorID AS vendor_id,
        ai.TotalAvailQOH AS total_avail_qoh,
        ai.Price AS price,
        ai.CurrentPrice AS current_price,
        ai.RetailPrice AS retail_price,
        ai.SpecialPrice AS special_price,
        ai.UpdateDateTime AS updated_at
      FROM eweb_active_items ai
      WHERE ai.is_deleted = 0
        AND ai.TotalAvailQOH > 0
        AND ai.TotalAvailQOH <= :threshold
      ORDER BY ai.TotalAvailQOH ASC, ai.UpdateDateTime DESC
      LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':threshold' => $threshold]);
    $items = $stmt->fetchAll();

    ai_json_out([
        'timezone' => $tz,
        'threshold' => $threshold,
        'count' => count($items),
        'items' => $items,
        'note' => 'Low stock = TotalAvailQOH > 0 and <= threshold. Negative QOH is treated as out-of-stock/anomaly.',
    ]);

} catch (Throwable $e) {
    error_log('[low-stock] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
