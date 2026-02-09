<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function dbg(string $msg): void {
    if ((getenv('APP_DEBUG') ?: '') === '1') {
        error_log('[sales-today] ' . $msg);
    }
}

// --------------------
// Auth gate
// --------------------
$chatToken = getenv('CHAT_TOKEN') ?: '';
if ($chatToken !== '') {
    $provided = $_SERVER['HTTP_X_CHAT_TOKEN'] ?? '';
    if (!is_string($provided) || !hash_equals($chatToken, $provided)) {
        json_out(['error' => 'Unauthorized'], 401);
    }
}
dbg('auth_ok');

// --------------------
// Config
// --------------------
$tzName = getenv('REPORT_TZ') ?: 'Australia/Melbourne';

$timestampCol = getenv('DELTA_TIMESTAMP_COL') ?: 'computed_at';
$allowedCols = ['computed_at'];
if (!in_array($timestampCol, $allowedCols, true)) {
    json_out(['error' => 'Server misconfig', 'detail' => "DELTA_TIMESTAMP_COL not allowed: {$timestampCol}"], 500);
}

$limit = 10;
if (isset($_GET['limit'])) {
    $limit = (int)$_GET['limit'];
    if ($limit < 1) $limit = 1;
    if ($limit > 200) $limit = 200;
}

// --------------------
// DB
// --------------------
function db(): PDO {
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'mcpserver';
    $user = getenv('DB_USER') ?: 'mcpserver_app';
    $pass = getenv('DB_PASS') ?: '';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 3,
    ];
    $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET SESSION wait_timeout=30, innodb_lock_wait_timeout=10";

    error_log('[sales-today] db_connect_start');
    $pdo = new PDO($dsn, $user, $pass, $options);
    error_log('[sales-today] db_connect_ok');

    return $pdo;
}

try {
    // --------------------
    // Compute "today" boundaries in Australia/Melbourne
    // --------------------
    $tz = new DateTimeZone($tzName);
    $utc = new DateTimeZone('UTC');

    $nowLocal = new DateTimeImmutable('now', $tz);
    $startLocal = $nowLocal->setTime(0, 0, 0);
    $endLocal = $startLocal->modify('+1 day');

    // Convert to UTC for DB filtering (assumes computed_at is stored consistently, ideally UTC)
    $startUtc = $startLocal->setTimezone($utc)->format('Y-m-d H:i:s');
    $endUtc   = $endLocal->setTimezone($utc)->format('Y-m-d H:i:s');

    $pdo = db();

    // --------------------
    // Total units
    // --------------------
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(ABS(d.delta)), 0) AS total_units
        FROM eweb_inventory_deltas d
        WHERE d.delta < 0
          AND d.{$timestampCol} >= :startUtc
          AND d.{$timestampCol} <  :endUtc
    ");
    $stmt->execute([':startUtc' => $startUtc, ':endUtc' => $endUtc]);
    $totalUnits = (int)round((float)(($stmt->fetch()['total_units'] ?? 0)));

    // --------------------
    // SKU breakdown + prices
    // FIX: force collations to match in JOIN
    // --------------------
    $sql = "
        SELECT
            agg.sku,
            agg.units,
            ai.Description AS description,
            COALESCE(ai.RetailPrice, ai.CurrentPrice, ai.Price) AS retail_price
        FROM (
            SELECT
                d.sku AS sku,
                SUM(ABS(d.delta)) AS units
            FROM eweb_inventory_deltas d
            WHERE d.delta < 0
              AND d.{$timestampCol} >= :startUtc
              AND d.{$timestampCol} <  :endUtc
            GROUP BY d.sku
        ) AS agg
        LEFT JOIN eweb_active_items ai
          ON ai.SKU = (agg.sku COLLATE utf8mb4_0900_ai_ci)
        ORDER BY agg.units DESC
        LIMIT {$limit}
    ";

    $stmt2 = $pdo->prepare($sql);
    $stmt2->execute([':startUtc' => $startUtc, ':endUtc' => $endUtc]);
    $rows = $stmt2->fetchAll();

    // --------------------
    // Totals + response
    // --------------------
    $totalValue = 0.0;
    $pricedUnits = 0;
    $missingPriceCount = 0;

    $breakdown = [];
    foreach ($rows as $r) {
        $sku = (string)($r['sku'] ?? '');
        $units = (int)round((float)($r['units'] ?? 0));

        $priceRaw = $r['retail_price'] ?? null;
        $price = null;
        if ($priceRaw !== null && $priceRaw !== '') {
            $price = (float)$priceRaw;
        }

        $lineTotal = null;
        if ($price !== null) {
            $lineTotal = $units * $price;
            $totalValue += $lineTotal;
            $pricedUnits += $units;
        } else {
            $missingPriceCount++;
        }

        $desc = $r['description'] ?? null;
        $desc = is_string($desc) ? trim($desc) : null;
        if ($desc === '') $desc = null;

        $breakdown[] = [
            'sku' => $sku,
            'units' => $units,
            'retail_price' => $price,
            'line_total' => $lineTotal,
            'description' => $desc,
        ];
    }

    $summary = sprintf(
        "Estimated retail value of today's sales is %.2f AUD (sum of all units sold × retail price).",
        round($totalValue, 2)
    );

    if ($missingPriceCount > 0) {
        $summary .= sprintf(
            " Note: %d SKU(s) in the top list have no retail price available and are excluded from the value total.",
            $missingPriceCount
        );
    }

    json_out([
        'date' => $startLocal->format('Y-m-d'),
        'timezone' => $tzName,
        'window_utc' => ['start' => $startUtc, 'end' => $endUtc],
        'total_units' => $totalUnits,
        'total_value_aud' => round($totalValue, 2),
        'priced_units' => $pricedUnits,
        'sku_breakdown' => $breakdown,
        'summary' => $summary,
        'note' => 'Sales are inferred from inventory decreases (delta < 0). This may include adjustments, transfers, or stock corrections.',
    ], 200);

} catch (Throwable $e) {
    error_log('[sales-today] ERROR: ' . $e->getMessage());
    json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
