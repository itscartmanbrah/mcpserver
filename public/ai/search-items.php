<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

try {
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    if ($q === '') {
        ai_json_out(['error' => 'Missing required parameter: q'], 400);
    }

    $limit = ai_limit(25);
    $pdo = ai_db();

    // Escape LIKE wildcards safely
    $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q);
    $like = '%' . $like . '%';

    $sql = "
      SELECT
        ai.SKU AS sku,
        ai.Description AS description,
        ai.DesignNum AS design_num,
        ai.Barcode AS barcode,
        ai.BrandID AS brand_id,
        ai.CategoryID AS category_id,
        ai.VendorID AS vendor_id,
        ai.TotalAvailQOH AS qoh,
        COALESCE(ai.RetailPrice, ai.CurrentPrice, ai.Price) AS retail_price
      FROM eweb_active_items ai
      WHERE ai.is_deleted = 0
        AND (
          ai.SKU        LIKE :l1 ESCAPE '\\\\'
          OR ai.Description LIKE :l2 ESCAPE '\\\\'
          OR ai.DesignNum   LIKE :l3 ESCAPE '\\\\'
          OR ai.Barcode     LIKE :l4 ESCAPE '\\\\'
          OR ai.BrandID     LIKE :l5 ESCAPE '\\\\'
          OR ai.VendorID    LIKE :l6 ESCAPE '\\\\'
        )
      ORDER BY ai.UpdateDateTime DESC
      LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':l1' => $like,
        ':l2' => $like,
        ':l3' => $like,
        ':l4' => $like,
        ':l5' => $like,
        ':l6' => $like,
    ]);

    $rows = $stmt->fetchAll();

    ai_json_out([
        'q' => $q,
        'count' => count($rows),
        'items' => $rows,
    ], 200);

} catch (Throwable $e) {
    error_log('[search-items] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
