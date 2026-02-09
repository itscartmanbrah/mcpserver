<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

$tz = getenv('REPORT_TZ') ?: 'Australia/Melbourne';
date_default_timezone_set($tz);

try {
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
    if ($days < 1) $days = 1;
    if ($days > 90) $days = 90;

    $limit = ai_limit(500);

    $pdo = ai_db();

    // Back in stock = prev run QOH <= 0 AND current run QOH > 0
    // Uses latest two SUCCESS sync runs within last N days.
    // NOTE: We use distinct placeholders because PDO MySQL native prepares can’t reuse named params reliably.
    $sql = "
      SELECT
        ai.SKU AS sku,
        ai.Description AS description,
        ai.BrandID AS brand_id,
        ai.CategoryID AS category_id,
        ai.VendorID AS vendor_id,
        ai.Price AS price,
        ai.CurrentPrice AS current_price,
        ai.RetailPrice AS retail_price,
        ai.SpecialPrice AS special_price,
        ai.UpdateDateTime AS updated_at,

        cur.qoh AS cur_qoh,
        cur.captured_at AS cur_snapshot_at,
        prev.qoh AS prev_qoh,
        prev.captured_at AS prev_snapshot_at,

        cur.sync_run_id AS cur_sync_run_id,
        prev.sync_run_id AS prev_sync_run_id

      FROM eweb_inventory_snapshots cur

      JOIN eweb_inventory_snapshots prev
        ON prev.sku = cur.sku
       AND prev.sync_run_id = (
            SELECT sr2.id
            FROM sync_runs sr2
            WHERE sr2.status = 'success'
              AND sr2.finished_at IS NOT NULL
              AND sr2.finished_at >= (NOW() - INTERVAL :days_prev DAY)
            ORDER BY sr2.id DESC
            LIMIT 1 OFFSET 1
       )

      JOIN eweb_active_items ai
        ON ai.is_deleted = 0
       AND " . ai_sku_join_on("ai.SKU", "cur.sku") . "

      WHERE cur.sync_run_id = (
            SELECT sr1.id
            FROM sync_runs sr1
            WHERE sr1.status = 'success'
              AND sr1.finished_at IS NOT NULL
              AND sr1.finished_at >= (NOW() - INTERVAL :days_cur DAY)
            ORDER BY sr1.id DESC
            LIMIT 1
      )
        AND cur.qoh > 0
        AND prev.qoh <= 0

      ORDER BY cur.captured_at DESC
      LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':days_prev', $days, PDO::PARAM_INT);
    $stmt->bindValue(':days_cur', $days, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll();

    // Fetch run ids for metadata
    $sqlRunMeta = "
      SELECT
        (SELECT sr1.id
         FROM sync_runs sr1
         WHERE sr1.status = 'success'
           AND sr1.finished_at IS NOT NULL
           AND sr1.finished_at >= (NOW() - INTERVAL :days_meta_cur DAY)
         ORDER BY sr1.id DESC
         LIMIT 1) AS cur_run_id,

        (SELECT sr2.id
         FROM sync_runs sr2
         WHERE sr2.status = 'success'
           AND sr2.finished_at IS NOT NULL
           AND sr2.finished_at >= (NOW() - INTERVAL :days_meta_prev DAY)
         ORDER BY sr2.id DESC
         LIMIT 1 OFFSET 1) AS prev_run_id
    ";
    $stmt2 = $pdo->prepare($sqlRunMeta);
    $stmt2->bindValue(':days_meta_cur', $days, PDO::PARAM_INT);
    $stmt2->bindValue(':days_meta_prev', $days, PDO::PARAM_INT);
    $stmt2->execute();
    $runs = $stmt2->fetch() ?: ['cur_run_id' => null, 'prev_run_id' => null];

    ai_json_out([
        'timezone' => $tz,
        'days' => $days,
        'limit' => $limit,
        'cur_run_id' => $runs['cur_run_id'],
        'prev_run_id' => $runs['prev_run_id'],
        'count' => count($items),
        'items' => $items,
        'note' => 'Back in stock is detected between the latest two SUCCESS sync runs within the last N days: prev QOH <= 0 and current QOH > 0.',
    ]);

} catch (Throwable $e) {
    error_log('[back-in-stock] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
