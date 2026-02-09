<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

try {
    $limit = ai_limit(500);
    $pdo = ai_db();

    $sql = "
      SELECT
        ai.VendorID AS vendor_id,
        COUNT(*) AS sku_count,
        SUM(COALESCE(ai.TotalAvailQOH, 0)) AS total_qoh
      FROM eweb_active_items ai
      WHERE ai.is_deleted = 0
        AND ai.VendorID IS NOT NULL
        AND ai.VendorID <> ''
      GROUP BY ai.VendorID
      ORDER BY sku_count DESC
      LIMIT {$limit}
    ";

    $rows = $pdo->query($sql)->fetchAll();

    ai_json_out([
        'count' => count($rows),
        'vendors' => array_map(fn($r) => [
            'vendor_id' => (string)$r['vendor_id'],
            'sku_count' => (int)$r['sku_count'],
            'total_qoh' => (float)$r['total_qoh'],
        ], $rows),
    ], 200);

} catch (Throwable $e) {
    error_log('[vendors] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
