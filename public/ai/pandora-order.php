<?php
declare(strict_types=1);

require __DIR__ . '/_lib.php';

// Output CSV
header('Content-Type: text/csv; charset=utf-8');

$tzName = getenv('REPORT_TZ') ?: 'Australia/Melbourne';
$tz = new DateTimeZone($tzName);
$now = new DateTimeImmutable('now', $tz);

$filename = 'pandora_order_' . $now->format('Y-m-d') . '.csv';
header('Content-Disposition: attachment; filename="' . $filename . '"');

try {
    $pdo = ai_db();

    // Build-to vs current QOH (Pandora only).
    // Negative QOH is treated as 0.
    $sql = "
        SELECT
            m.design_num AS item,
            COALESCE(NULLIF(m.description, ''), '') AS description,
            COALESCE(NULLIF(m.department, ''), '') AS department,
            CAST(GREATEST(0, m.min_qty - COALESCE(inv.qoh, 0)) AS UNSIGNED) AS quantity
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
        HAVING quantity > 0
        ORDER BY m.department ASC, m.design_num ASC
    ";

    $stmt = $pdo->query($sql);

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        throw new RuntimeException('Unable to open output stream');
    }

    // Required CSV header
    fputcsv($out, ['Item', 'Description', 'Department', 'Quantity', 'Price']);

    // Price must be blank per your rule
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            (string)($row['item'] ?? ''),
            (string)($row['description'] ?? ''),
            (string)($row['department'] ?? ''),
            (string)($row['quantity'] ?? '0'),
            '',
        ]);
    }

    fclose($out);

} catch (Throwable $e) {
    error_log('[pandora-order] ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo "Server error\n";
}
