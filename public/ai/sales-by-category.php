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

    // --- Params ---
    $limit = ai_limit(50);
    if ($limit > 500) $limit = 500;

    $dateParam  = isset($_GET['date']) ? (string)$_GET['date'] : '';
    $startParam = isset($_GET['start']) ? (string)$_GET['start'] : '';
    $endParam   = isset($_GET['end']) ? (string)$_GET['end'] : '';
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
        if ($days < 1) $days = 1;
        if ($days > 90) $days = 90;

        $today = $now->setTime(0, 0, 0);
        $startDay = $today->modify('-' . ($days - 1) . ' days');
        $endDayExclusive = $today->modify('+1 day');
    }

    // --- Schema checks ---
    if (!ai_table_exists($pdo, 'eweb_inventory_deltas')) {
        ai_json_out(['error' => 'Missing required table: eweb_inventory_deltas'], 500);
    }
    if (!ai_table_exists($pdo, 'eweb_active_items')) {
        ai_json_out(['error' => 'Missing required table: eweb_active_items'], 500);
    }

    $deltaCols = ai_table_columns($pdo, 'eweb_inventory_deltas');
    $itemCols  = ai_table_columns($pdo, 'eweb_active_items');

    $tsColWanted = strtolower(ai_timestamp_col());
    $tsCol = $deltaCols[$tsColWanted] ?? null;
    if ($tsCol === null) {
        foreach (['computed_at', 'created_at', 'captured_at'] as $cand) {
            if (isset($deltaCols[$cand])) { $tsCol = $deltaCols[$cand]; break; }
        }
    }
    if ($tsCol === null) {
        ai_json_out(['error' => 'Could not determine delta timestamp column (check DELTA_TIMESTAMP_COL)'], 500);
    }

    $skuCol = $deltaCols['sku'] ?? null;
    if ($skuCol === null) {
        foreach ($deltaCols as $k => $actual) {
            if ($k === 'item_sku') { $skuCol = $actual; break; }
        }
    }
    if ($skuCol === null) {
        ai_json_out(['error' => 'Could not determine SKU column in eweb_inventory_deltas'], 500);
    }

    $deltaCol = $deltaCols['delta'] ?? null;
    if ($deltaCol === null) {
        foreach (['qty_delta','qoh_delta','change','net_change'] as $cand) {
            if (isset($deltaCols[$cand])) { $deltaCol = $deltaCols[$cand]; break; }
        }
    }
    if ($deltaCol === null) {
        ai_json_out(['error' => 'Could not determine delta column in eweb_inventory_deltas'], 500);
    }

    $aiSkuCol = $itemCols['sku'] ?? 'SKU';
    $aiCatCol = $itemCols['categoryid'] ?? 'CategoryID';

    // Price estimate
    $priceExpr = "COALESCE(ai.RetailPrice, ai.CurrentPrice, ai.Price, 0)";

    // Category normalization: NULL/'' => 'NO_CATEGORY'
    // CategoryID is often numeric; we cast to CHAR to keep output consistent.
    $catIdExpr = "COALESCE(NULLIF(CAST(ai.`{$aiCatCol}` AS CHAR),''), 'NO_CATEGORY')";

    // Optional category names table
    $catJoinSql = "";
    $catNameSelect = "CASE WHEN {$catIdExpr} = 'NO_CATEGORY' THEN 'No category / Uncategorised' ELSE NULL END AS category_name";

    if (ai_table_exists($pdo, 'eweb_categories')) {
        $catCols = ai_table_columns($pdo, 'eweb_categories');
        $cId = $catCols['id'] ?? 'ID';
        $cName = $catCols['name'] ?? 'Name';

        $catJoinSql = "
          LEFT JOIN eweb_categories c
            ON {$catIdExpr} <> 'NO_CATEGORY'
           AND c.`{$cId}` = CAST({$catIdExpr} AS UNSIGNED)
        ";

        $catNameSelect = "
          CASE
            WHEN {$catIdExpr} = 'NO_CATEGORY' THEN 'No category / Uncategorised'
            ELSE c.`{$cName}`
          END AS category_name
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
        {$catIdExpr} AS category_id,
        {$catNameSelect},
        SUM(s.units_sold) AS units_sold,
        SUM(s.units_sold * {$priceExpr}) AS est_value
      FROM sales_by_sku s
      JOIN eweb_active_items ai
        ON ai.is_deleted = 0
       AND " . ai_sku_join_on("ai.`{$aiSkuCol}`", "s.sku") . "
      {$catJoinSql}
      GROUP BY category_id, category_name
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
        'categories' => $rows,
        'note' => 'Sales are inferred from negative inventory deltas (delta < 0). Estimated value uses COALESCE(RetailPrice, CurrentPrice, Price) from current ActiveItem data and may include adjustments. Items with no CategoryID are grouped under category_id=NO_CATEGORY.',
    ]);

} catch (Throwable $e) {
    error_log('[sales-by-category] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
