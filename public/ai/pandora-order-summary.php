<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';

// PUBLIC by request: no auth.

try {
    $pdo = ai_db();

    $sql = "
      SELECT
        COUNT(*) AS order_lines,
        COALESCE(SUM(x.qty), 0) AS total_units
      FROM (
        SELECT
          m.design_num,
          CAST(GREATEST(0, m.min_qty - COALESCE(inv.qoh, 0)) AS UNSIGNED) AS qty
        FROM pandora_master_list m
        LEFT JOIN (
          SELECT
            ai.RealDesignNum AS design_num,
            SUM(GREATEST(COALESCE(ai.TotalAvailQOH, 0), 0)) AS qoh
          FROM eweb_active_items ai
          WHERE ai.is_deleted = 0
            AND ai.VendorID = 'PANDO'
            AND ai.RealDesignNum IS NOT NULL
            AND ai.RealDesignNum <> ''
          GROUP BY ai.RealDesignNum
        ) inv ON inv.design_num = m.design_num
        WHERE m.is_discontinued = 0
          AND m.min_qty > 0
      ) x
      WHERE x.qty > 0
    ";

    $row = $pdo->query($sql)->fetch() ?: [];
    $orderLines = (int)($row['order_lines'] ?? 0);
    $totalUnits = (int)($row['total_units'] ?? 0);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'mcp.burrowsjewellers.com.au';

    $csvUrl = "{$scheme}://{$host}/ai/pandora-order.php";
    $downloadUrl = "{$scheme}://{$host}/ai/pandora-order-summary.php?download=1";

    $download = isset($_GET['download']) ? strtolower(trim((string)$_GET['download'])) : '';
    if (in_array($download, ['1','true','yes'], true)) {
        header("Location: {$csvUrl}", true, 302);
        exit;
    }

    echo json_encode([
        'vendor_id'    => 'PANDO',
        'order_lines'  => $orderLines,
        'total_units'  => $totalUnits,
        'download_url' => $downloadUrl,
        'csv_url'      => $csvUrl,
        'note'         => 'Order quantities are build-to min_qty minus current on-hand (negative treated as 0).',
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('[pandora-order-summary] ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'detail' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
