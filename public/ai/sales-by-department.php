<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

$tz = getenv('REPORT_TZ') ?: 'Australia/Melbourne';
date_default_timezone_set($tz);

function ai_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :t
        LIMIT 1
    ");
    $stmt->execute([':t' => $table]);
    return (bool)$stmt->fetchColumn();
}

function ai_table_columns(PDO $pdo, string $table): array {
    $stmt = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = :t
    ");
    $stmt->execute([':t' => $table]);
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $cols[strtolower((string)$c)] = (string)$c;
    }
    return $cols;
}

function ai_parse_date(string $s): ?DateTimeImmutable {
    $s = trim($s);
    if ($s === '') return null;
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $s);
    if (!$dt) return null;
    return $dt->setTime(0, 0, 0);
}

try {
    $pdo = ai_db();

    // ---- Params ----
    $limit = ai_limit(50);
    if ($limit > 500) $limit = 500;

    $dateParam  = $_GET['date']  ?? '';
    $startParam = $_GET['start'] ?? '';
    $endParam   = $_GET['end']   ?? '';
    $daysParam  = isset($_GET['days']) ? (int)$_GET['days'] : 0;

    $now = new DateTimeImmutable('now');
    $startDay = null;
    $endDayExclusive = null;

    if (($d = ai_parse_date($dateParam)) !== null) {
        $startDay = $d;
        $endDayExclusive = $d->modify('+1 day');
    } elseif (($s = ai_parse_date($startParam)) !== null && ($e = ai_parse_date($endParam)) !== null) {
        if ($e < $s) {
            ai_json_out(['error' => 'Invalid range: end must be >= start'], 400);
        }
        $startDay = $s;
        $endDayExclusive = $e->modify('+1 day');
    } else {
        $days = $daysParam > 0 ? $daysParam : 1;
        if ($days > 90) $days = 90;

        $today = $now->setTime(0, 0, 0);
        $startDay = $today->modify('-' . ($days - 1) . ' days');
        $endDayExclusive = $today->modify('+1 day');
    }

    // ---- Schema ----
    $deltaCols = ai_table_columns($pdo, 'eweb_inventory_deltas');
    $itemCols  = ai_table_columns($pdo, 'eweb_active_items');

    $tsCol = $deltaCols[strtolower(ai_timestamp_col())] ?? 'computed_at';
    $skuCol = $deltaCols['sku'];
    $deltaCol = $deltaCols['delta'];

    $aiSkuCol = $itemCols['sku'] ?? 'SKU';
    $aiDeptCol = $itemCols['categoryid'] ?? 'CategoryID';

    $priceExpr = "COALESCE(ai.RetailPrice, ai.CurrentPrice, ai.Price, 0)";

    // Department normalization
    $deptIdExpr = "COALESCE(NULLIF(CAST(ai.`{$aiDeptCol}` AS CHAR),''), 'NO_DEPARTMENT')";

    $deptJoinSql = "";
    $deptNameSelect = "
      CASE
        WHEN {$deptIdExpr} = 'NO_DEPARTMENT' THEN 'No department / Unassigned'
        ELSE NULL
      END AS department_name
    ";

    if (ai_table_exists($pdo, 'eweb_categories')) {
        $deptCols = ai_table_columns($pdo, 'eweb_categories');
        $dId = $deptCols['id'] ?? 'ID';
        $dName = $deptCols['name'] ?? 'Name';

        $deptJoinSql = "
          LEFT JOIN eweb_categories d
            ON {$deptIdExpr} <> 'NO_DEPARTMENT'
           AND d.`{$dId}` = CAST({$deptIdExpr} AS UNSIGNED)
        ";

        $deptNameSelect = "
          CASE
            WHEN {$deptIdExpr} = 'NO_DEPARTMENT' THEN 'No department / Unassigned'
            ELSE d.`{$dName}`
          END AS department_name
        ";
    }

    $sql = "
      WITH sales_by_sku AS (
        SELECT
          d.`{$skuCol}` AS sku,
          SUM(-d.`{$deltaCol}`) AS units_sold
        FROM eweb_inventory_deltas d
        WHERE d.`{$tsCol}` >= :start_dt
          AND d.`{$tsCol}` < :end_dt
          AND d.`{$deltaCol}` < 0
        GROUP BY d.`{$skuCol}`
      )
      SELECT
        {$deptIdExpr} AS department_id,
        {$deptNameSelect},
        SUM(s.units_sold) AS units_sold,
        SUM(s.units_sold * {$priceExpr}) AS est_value
      FROM sales_by_sku s
      JOIN eweb_active_items ai
        ON ai.is_deleted = 0
       AND " . ai_sku_join_on("ai.`{$aiSkuCol}`", "s.sku") . "
      {$deptJoinSql}
      GROUP BY department_id, department_name
      HAVING units_sold > 0
      ORDER BY est_value DESC, units_sold DESC
      LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':start_dt', $startDay->format('Y-m-d H:i:s'));
    $stmt->bindValue(':end_dt', $endDayExclusive->format('Y-m-d H:i:s'));
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();

    $totUnits = 0.0;
    $totValue = 0.0;
    foreach ($rows as $r) {
        $totUnits += (float)$r['units_sold'];
        $totValue += (float)$r['est_value'];
    }

    ai_json_out([
        'timezone' => $tz,
        'start' => $startDay->format('Y-m-d'),
        'end' => $endDayExclusive->modify('-1 day')->format('Y-m-d'),
        'count' => count($rows),
        'totals' => [
            'units_sold' => $totUnits,
            'est_value' => $totValue,
        ],
        'departments' => $rows,
        'note' => 'Sales are inferred from negative inventory deltas (delta < 0). Estimated value uses COALESCE(RetailPrice, CurrentPrice, Price). Items with no department are grouped under department_id=NO_DEPARTMENT.',
    ]);

} catch (Throwable $e) {
    error_log('[sales-by-department] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
