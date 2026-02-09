<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

try {
    $sku = isset($_GET['sku']) ? trim((string)$_GET['sku']) : '';
    if ($sku === '') {
        ai_json_out(['error' => 'Missing required parameter: sku'], 400);
    }

    $pdo = ai_db();

    // ---- Item core ----
    $sqlItem = "
      SELECT
        ai.SKU AS sku,
        ai.Description AS description,
        ai.MarketingDescription AS marketing_description,
        ai.ShortMarketingDescription AS short_marketing_description,
        ai.CustomTitle AS custom_title,
        ai.CustomDescription AS custom_description,
        ai.BrandID AS brand_id,
        ai.CategoryID AS category_id,
        ai.VendorID AS vendor_id,
        ai.DesignNum AS design_num,
        ai.Barcode AS barcode,
        ai.TotalAvailQOH AS total_avail_qoh,
        ai.Cost AS cost,
        ai.Price AS price,
        ai.CurrentPrice AS current_price,
        ai.RetailPrice AS retail_price,
        ai.SpecialPrice AS special_price,
        ai.UpdateDateTime AS updated_at
      FROM eweb_active_items ai
      WHERE ai.is_deleted = 0
        AND " . ai_sku_join_on("ai.SKU", ":sku") . "
      LIMIT 1
    ";

    $stmt = $pdo->prepare($sqlItem);
    $stmt->execute([':sku' => $sku]);
    $item = $stmt->fetch();

    if (!$item) {
        ai_json_out(['error' => 'Not found', 'sku' => $sku], 404);
    }

    // ---- ISDs ----
    $sqlIsd = "
      SELECT `Index` AS idx, `Name` AS name, `Value` AS value
      FROM eweb_active_item_isds
      WHERE " . ai_sku_join_on("item_sku", ":sku") . "
      ORDER BY `Index` ASC
      LIMIT 500
    ";
    $stmt = $pdo->prepare($sqlIsd);
    $stmt->execute([':sku' => $sku]);
    $isds = $stmt->fetchAll();

    // ---- Images (your actual schema) ----
    $sqlImg = "
      SELECT
        `Index` AS idx,
        `URL` AS url,
        `Width` AS width,
        `Height` AS height,
        `UpdateDateTime` AS updated_at
      FROM eweb_active_item_images
      WHERE " . ai_sku_join_on("item_sku", ":sku") . "
      ORDER BY `Index` ASC
      LIMIT 200
    ";
    $stmt = $pdo->prepare($sqlImg);
    $stmt->execute([':sku' => $sku]);
    $images = $stmt->fetchAll();

    ai_json_out([
        'item' => $item,
        'isds' => $isds,
        'images' => $images,
    ], 200);

} catch (Throwable $e) {
    error_log('[item] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
