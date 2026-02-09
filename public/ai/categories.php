<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

try {
    $limit = ai_limit(500);
    if ($limit > 2000) $limit = 2000;

    $pdo = ai_db();

    // Detect whether eweb_categories exists
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'eweb_categories'
        LIMIT 1
    ");
    $stmt->execute();
    $hasCats = (bool)$stmt->fetchColumn();

    if ($hasCats) {
        $sql = "
          SELECT
            ai.CategoryID AS category_id,
            COALESCE(c.Name, CONCAT('Category ', ai.CategoryID)) AS category_name,
            c.Active AS category_active,
            c.ParentID AS parent_id,
            c.UpdateDateTime AS category_updated_at,
            COUNT(*) AS sku_count,
            SUM(COALESCE(ai.TotalAvailQOH, 0)) AS total_qoh
          FROM eweb_active_items ai
          LEFT JOIN eweb_categories c
            ON c.CategoryID = ai.CategoryID
          WHERE ai.is_deleted = 0
            AND ai.CategoryID IS NOT NULL
          GROUP BY
            ai.CategoryID,
            c.Name,
            c.Active,
            c.ParentID,
            c.UpdateDateTime
          ORDER BY sku_count DESC
          LIMIT {$limit}
        ";

        $rows = $pdo->query($sql)->fetchAll();

        ai_json_out([
            'count' => count($rows),
            'categories' => array_map(static fn($r) => [
                'category_id' => (int)$r['category_id'],
                'category_name' => (string)$r['category_name'],
                'active' => ($r['category_active'] === null ? null : (int)$r['category_active']),
                'parent_id' => ($r['parent_id'] === null ? null : (int)$r['parent_id']),
                'category_updated_at' => ($r['category_updated_at'] === null ? null : (string)$r['category_updated_at']),
                'sku_count' => (int)$r['sku_count'],
                'total_qoh' => (float)$r['total_qoh'],
            ], $rows),
        ], 200);
    }

    // Fallback if eweb_categories does not exist
    $sql = "
      SELECT
        ai.CategoryID AS category_id,
        COUNT(*) AS sku_count,
        SUM(COALESCE(ai.TotalAvailQOH, 0)) AS total_qoh
      FROM eweb_active_items ai
      WHERE ai.is_deleted = 0
        AND ai.CategoryID IS NOT NULL
      GROUP BY ai.CategoryID
      ORDER BY sku_count DESC
      LIMIT {$limit}
    ";

    $rows = $pdo->query($sql)->fetchAll();

    ai_json_out([
        'count' => count($rows),
        'categories' => array_map(static fn($r) => [
            'category_id' => (int)$r['category_id'],
            'sku_count' => (int)$r['sku_count'],
            'total_qoh' => (float)$r['total_qoh'],
        ], $rows),
        'note' => 'eweb_categories table not found; returning category_id only.',
    ], 200);

} catch (Throwable $e) {
    error_log('[categories] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
