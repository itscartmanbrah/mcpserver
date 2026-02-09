<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

try {
    $since = isset($_GET['since']) ? (string)$_GET['since'] : '';
    if ($since === '') ai_json_out(['error' => 'Missing required parameter: since (YYYY-MM-DD)'], 400);

    $limit = ai_limit(50);
    $pdo = ai_db();

    $sql = "
      SELECT
        ai.SKU AS sku,
        ai.Description AS description,
        COALESCE(ai.RetailPrice, ai.CurrentPrice, ai.Price) AS retail_price,
        ai.BrandID AS brand_id,
        ai.CategoryID AS category_id,
        ai.VendorID AS vendor_id,
        ai.created_at
      FROM eweb_active_items ai
      WHERE ai.is_deleted = 0
        AND ai.created_at >= :since
      ORDER BY ai.created_at DESC
      LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':since' => $since . ' 00:00:00']);
    $rows = $stmt->fetchAll();

    ai_json_out([
        'since' => $since,
        'count' => count($rows),
        'items' => $rows,
        'note' => 'New items uses local insert time (eweb_active_items.created_at).',
    ], 200);

} catch (Throwable $e) {
    error_log('[new-items] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
