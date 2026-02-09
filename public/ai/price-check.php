<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

try {
    $sku = isset($_GET['sku']) ? trim((string)$_GET['sku']) : '';
    if ($sku === '') ai_json_out(['error' => 'Missing required parameter: sku'], 400);

    $pdo = ai_db();

    $sql = "
      SELECT
        ai.SKU AS sku,
        ai.Description AS description,
        ai.Price AS price,
        ai.CurrentPrice AS current_price,
        ai.RetailPrice AS retail_price,
        ai.SpecialPrice AS special_price,
        ai.CataloguePrice AS catalogue_price,
        ai.CataloguePriceStart AS catalogue_price_start,
        ai.CataloguePriceEnd AS catalogue_price_end,
        ai.SpecialPriceStart AS special_price_start,
        ai.SpecialPriceEnd AS special_price_end,
        ai.UpdateDateTime AS updated_at
      FROM eweb_active_items ai
      WHERE ai.is_deleted = 0
        AND " . ai_sku_join_on("ai.SKU", ":sku") . "
      LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':sku' => $sku]);
    $row = $stmt->fetch();

    if (!$row) ai_json_out(['error' => 'Not found', 'sku' => $sku], 404);

    ai_json_out(['item' => $row], 200);

} catch (Throwable $e) {
    error_log('[price-check] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
