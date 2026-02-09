<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

try {
    $limit = ai_limit(500);
    if ($limit > 2000) $limit = 2000;

    $pdo = ai_db();

    // Detect whether eweb_brands exists (brand names)
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'eweb_brands'
        LIMIT 1
    ");
    $stmt->execute();
    $hasBrands = (bool)$stmt->fetchColumn();

    if ($hasBrands) {
        $sql = "
          SELECT
            ai.BrandID AS brand_id,
            COALESCE(b.Name, 'Unknown brand') AS brand_name,
            b.Active AS active,
            b.UpdateDateTime AS brand_updated_at,
            COUNT(*) AS sku_count,
            SUM(COALESCE(ai.TotalAvailQOH, 0)) AS total_qoh
          FROM eweb_active_items ai
          LEFT JOIN eweb_brands b
            ON b.BrandID = ai.BrandID
          WHERE ai.is_deleted = 0
            AND ai.BrandID IS NOT NULL
            AND ai.BrandID <> ''
          GROUP BY ai.BrandID, b.Name, b.Active, b.UpdateDateTime
          ORDER BY sku_count DESC
          LIMIT {$limit}
        ";

        $rows = $pdo->query($sql)->fetchAll();

        ai_json_out([
            'count' => count($rows),
            'brands' => array_map(static fn($r) => [
                'brand_id' => (string)$r['brand_id'],
                'brand_name' => $r['brand_name'] === null ? null : (string)$r['brand_name'],
                'active' => $r['active'] === null ? null : (int)$r['active'],
                'brand_updated_at' => $r['brand_updated_at'] === null ? null : (string)$r['brand_updated_at'],
                'sku_count' => (int)$r['sku_count'],
                'total_qoh' => (float)$r['total_qoh'],
            ], $rows),
        ], 200);
    }

    // Fallback (no eweb_brands table)
    $sql = "
      SELECT
        ai.BrandID AS brand_id,
        COUNT(*) AS sku_count,
        SUM(COALESCE(ai.TotalAvailQOH, 0)) AS total_qoh
      FROM eweb_active_items ai
      WHERE ai.is_deleted = 0
        AND ai.BrandID IS NOT NULL
        AND ai.BrandID <> ''
      GROUP BY ai.BrandID
      ORDER BY sku_count DESC
      LIMIT {$limit}
    ";

    $rows = $pdo->query($sql)->fetchAll();

    ai_json_out([
        'count' => count($rows),
        'brands' => array_map(static fn($r) => [
            'brand_id' => (string)$r['brand_id'],
            'sku_count' => (int)$r['sku_count'],
            'total_qoh' => (float)$r['total_qoh'],
        ], $rows),
        'note' => 'eweb_brands table not found; returning brand_id only.',
    ], 200);

} catch (Throwable $e) {
    error_log('[brands] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
