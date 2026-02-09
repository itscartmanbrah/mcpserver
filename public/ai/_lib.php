<?php
declare(strict_types=1);

function ai_json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function ai_dbg(string $msg): void {
    if ((getenv('APP_DEBUG') ?: '') === '1') {
        error_log('[ai] ' . $msg);
    }
}

function ai_auth_gate(): void {
    $chatToken = getenv('CHAT_TOKEN') ?: '';
    if ($chatToken !== '') {
        $provided = $_SERVER['HTTP_X_CHAT_TOKEN'] ?? '';
        if (!is_string($provided) || !hash_equals($chatToken, $provided)) {
            ai_json_out(['error' => 'Unauthorized'], 401);
        }
    }
}

function ai_db(): PDO {
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'mcpserver';
    $user = getenv('DB_USER') ?: 'mcpserver_app';
    $pass = getenv('DB_PASS') ?: '';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 3,
    ];
    $opts[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET SESSION wait_timeout=30, innodb_lock_wait_timeout=10";

    $pdo = new PDO($dsn, $user, $pass, $opts);
    return $pdo;
}

/**
 * Returns [startLocal, endLocal, startUtcStr, endUtcStr, tzName]
 * If $fromDate/$toDate provided, they must be YYYY-MM-DD in local timezone.
 * toDate is inclusive (we convert to exclusive end by +1 day at 00:00).
 */
function ai_window_utc(?string $fromDate, ?string $toDate): array {
    $tzName = getenv('REPORT_TZ') ?: 'Australia/Melbourne';
    $tz = new DateTimeZone($tzName);
    $utc = new DateTimeZone('UTC');

    if ($fromDate === null && $toDate === null) {
        $nowLocal = new DateTimeImmutable('now', $tz);
        $startLocal = $nowLocal->setTime(0, 0, 0);
        $endLocal = $startLocal->modify('+1 day');
    } else {
        $from = $fromDate ?? $toDate;
        $to = $toDate ?? $fromDate;

        if (!is_string($from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            ai_json_out(['error' => 'Invalid from date; expected YYYY-MM-DD'], 400);
        }
        if (!is_string($to) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            ai_json_out(['error' => 'Invalid to date; expected YYYY-MM-DD'], 400);
        }

        $startLocal = new DateTimeImmutable($from . ' 00:00:00', $tz);
        $endLocal = (new DateTimeImmutable($to . ' 00:00:00', $tz))->modify('+1 day');
        if ($endLocal < $startLocal) {
            ai_json_out(['error' => 'Invalid range; to must be >= from'], 400);
        }
    }

    $startUtc = $startLocal->setTimezone($utc)->format('Y-m-d H:i:s');
    $endUtc = $endLocal->setTimezone($utc)->format('Y-m-d H:i:s');

    return [$startLocal, $endLocal, $startUtc, $endUtc, $tzName];
}

function ai_limit(int $default = 10): int {
    $limit = $default;
    if (isset($_GET['limit'])) {
        $limit = (int)$_GET['limit'];
    }
    if ($limit < 1) $limit = 1;
    if ($limit > 200) $limit = 200;
    return $limit;
}

/**
 * Enforces a known timestamp column (prevents injection).
 */
function ai_timestamp_col(): string {
    $col = getenv('DELTA_TIMESTAMP_COL') ?: 'computed_at';
    $allowed = ['computed_at'];
    if (!in_array($col, $allowed, true)) {
        ai_json_out(['error' => 'Server misconfig', 'detail' => "DELTA_TIMESTAMP_COL not allowed: {$col}"], 500);
    }
    return $col;
}

/**
 * Price fallback: RetailPrice -> CurrentPrice -> Price
 */
function ai_price_expr(): string {
    return "COALESCE(ai.RetailPrice, ai.CurrentPrice, ai.Price)";
}

/**
 * Collation fix for comparing SKU across tables.
 */
function ai_sku_join_on(string $leftExpr, string $rightExpr): string {
    // Match MySQL 8 default collation for modern tables
    return "{$leftExpr} = ({$rightExpr} COLLATE utf8mb4_0900_ai_ci)";
}

function ai_note(): string {
    return 'Sales are inferred from inventory decreases (delta < 0). This may include adjustments, transfers, or stock corrections.';
}
