<?php
declare(strict_types=1);

// Allow CLI invocation:
// php sync-active-items-latest.php SYNC_TOKEN=... batch=50 cust_batch=200
if (PHP_SAPI === 'cli' && empty($_GET) && isset($argv)) {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}

set_time_limit(0);
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';

use App\Support\Db;

/* =========================
   Access gate (MASTER ONLY)
   ========================= */
$token = (string)($_GET['SYNC_TOKEN'] ?? ($_GET['token'] ?? ''));
$expected = (string)(getenv('SYNC_TOKEN') ?: '');

if ($expected === '') {
    http_response_code(500);
    exit("SYNC_TOKEN missing from environment (.env not loaded)\n");
}
if ($token === '') {
    http_response_code(403);
    exit("Forbidden: missing SYNC_TOKEN\n");
}
if (!hash_equals($expected, $token)) {
    http_response_code(403);
    exit("Forbidden: token mismatch\n");
}

/* =========================
   DB-backed sync lock
   ========================= */
function acquireSyncLock(PDO $pdo, string $name): void
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(:name, 0)'); // 0 = fail fast
    $stmt->execute(['name' => $name]);
    $ok = (int)$stmt->fetchColumn();

    if ($ok !== 1) {
        http_response_code(409);
        exit("Sync already running\n");
    }
}

function releaseSyncLock(PDO $pdo, string $name): void
{
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
    $stmt->execute(['name' => $name]);
}

/* =========================
   sync_runs logging
   ========================= */
function syncRunStart(PDO $pdo, string $jobName): int
{
    $stmt = $pdo->prepare("
        INSERT INTO sync_runs (job_name, started_at, status, message)
        VALUES (:job_name, NOW(), 'running', NULL)
    ");
    $stmt->execute([':job_name' => $jobName]);
    return (int)$pdo->lastInsertId();
}

function syncRunFinish(PDO $pdo, int $runId, string $status, ?string $message = null): void
{
    $stmt = $pdo->prepare("
        UPDATE sync_runs
        SET finished_at = NOW(),
            status = :status,
            message = :message
        WHERE id = :id
    ");
    $stmt->execute([
        ':status' => $status,
        ':message' => $message,
        ':id' => $runId,
    ]);
}

/* =========================
   HTTP call helper
   ========================= */
/**
 * Call a local sync endpoint over HTTP so it runs in a separate PHP request.
 * This avoids function redeclare issues and isolates memory usage.
 */
function callLocal(string $path, array $query): string
{
    $qs = http_build_query($query);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $url = $scheme . '://' . $host . $path . '?' . $qs;

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 600,
        ],
    ]);

    $out = @file_get_contents($url, false, $ctx);
    if ($out === false) {
        $err = error_get_last();
        $headers = '';

        // file_get_contents populates $http_response_header in local scope
        if (isset($http_response_header) && is_array($http_response_header)) {
            $headers = implode("\n", $http_response_header);
        }

        throw new RuntimeException(
            "HTTP call failed: {$url}\n" .
            ($headers ? "HTTP Response Headers:\n{$headers}\n" : '') .
            ($err['message'] ?? 'unknown error')
        );
    }

    return $out;
}

/* =========================
   Main
   ========================= */
echo "SYNC ACTIVE ITEMS (LATEST)\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

$pdo = Db::pdo();
$lockName = 'mcp_sync_active_items_latest';

acquireSyncLock($pdo, $lockName);

$jobName = 'sync-active-items-latest';
$runId = syncRunStart($pdo, $jobName);

try {
    try {
        $itemsBatch = (int)($_GET['batch'] ?? 50);
        if ($itemsBatch <= 0) $itemsBatch = 50;

        $custBatch = (int)($_GET['cust_batch'] ?? 200);
        if ($custBatch <= 0) $custBatch = 200;

        // 1) Active items upsert
        echo "1) Running GetAllActiveItems sync...\n";
        echo "----------------------------------------\n";
        echo callLocal('/mcpserver/public/sync/get-all-active-items.php', [
            'SYNC_TOKEN' => $token,
            'batch' => $itemsBatch,
        ]);

        // 2) Deleted items marker
        echo "\n\n2) Running Deleted Items marker...\n";
        echo "----------------------------------------\n";
        echo callLocal('/mcpserver/public/sync/get-deleted-active-items.php', [
            'SYNC_TOKEN' => $token,
        ]);

        // 3) Customers sync
        echo "\n\n3) Running Customers sync...\n";
        echo "----------------------------------------\n";
        echo callLocal('/mcpserver/public/sync/get-all-customers.php', [
            'SYNC_TOKEN' => $token,
            'batch' => $custBatch,
        ]);

        syncRunFinish($pdo, $runId, 'success', 'OK');

        echo "\n\nFinished: " . date('Y-m-d H:i:s') . "\n";
        echo "DONE\n";
    } catch (Throwable $e) {
        syncRunFinish($pdo, $runId, 'failed', $e->getMessage());
        http_response_code(500);
        echo "\nFAILED: " . $e->getMessage() . "\n";
    }
} finally {
    releaseSyncLock($pdo, $lockName);
}
