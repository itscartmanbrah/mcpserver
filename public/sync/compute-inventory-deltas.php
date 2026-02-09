<?php
declare(strict_types=1);

// Allow CLI invocation:
// php compute-inventory-deltas.php SYNC_TOKEN=... to_sync_run_id=29
if (PHP_SAPI === 'cli' && empty($_GET) && isset($argv)) {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}

set_time_limit(0);
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';

use App\Support\Db;

/* =========================
   Access gate
   ========================= */
$token = (string)($_GET['SYNC_TOKEN'] ?? ($_GET['token'] ?? ''));
$expected = (string)(getenv('SYNC_TOKEN') ?: '');

if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    fwrite(STDERR, "Forbidden\n");
    exit(1);
}

function fail(string $message, int $code = 400): void
{
    http_response_code($code);
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/* =========================
   Inputs
   ========================= */
$toRunId = (int)($_GET['to_sync_run_id'] ?? 0);
if ($toRunId <= 0) {
    fail("Missing or invalid to_sync_run_id", 400);
}

// Optional: allow computing deltas for a failed run (diagnostics/backfill only)
$allowFailed = ((string)($_GET['allow_failed'] ?? '') === '1');

echo "COMPUTE INVENTORY DELTAS\n";
echo "To run: {$toRunId}\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

$pdo = Db::pdo();

try {
    // Validate to_run exists and is allowed
    $stmt = $pdo->prepare("SELECT id, status FROM sync_runs WHERE id = ?");
    $stmt->execute([$toRunId]);
    $toRun = $stmt->fetch();

    if (!$toRun) {
        throw new RuntimeException("sync_runs row not found for id={$toRunId}");
    }

    $toStatus = (string)($toRun['status'] ?? '');
    if (!$allowFailed && !in_array($toStatus, ['running', 'success'], true)) {
        throw new RuntimeException("to_sync_run_id={$toRunId} is not running/success (status={$toStatus})");
    }

    // Determine from_run: most recent earlier SUCCESS run that has snapshots
    $stmt = $pdo->prepare("
        SELECT sr.id
        FROM sync_runs sr
        WHERE sr.id < ?
          AND sr.job_name = 'sync-active-items-latest'
          AND sr.status = 'success'
          AND EXISTS (
              SELECT 1 FROM eweb_inventory_snapshots s WHERE s.sync_run_id = sr.id
          )
        ORDER BY sr.id DESC
        LIMIT 1
    ");
    $stmt->execute([$toRunId]);
    $fromRunId = (int)($stmt->fetchColumn() ?: 0);

    if ($fromRunId <= 0) {
        throw new RuntimeException("No prior successful run with snapshots found before to_sync_run_id={$toRunId}");
    }

    echo "From run: {$fromRunId}\n";

    // Snapshot counts check
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM eweb_inventory_snapshots WHERE sync_run_id = ?");
    $stmt->execute([$fromRunId]);
    $fromCount = (int)$stmt->fetchColumn();

    $stmt->execute([$toRunId]);
    $toCount = (int)$stmt->fetchColumn();

    echo "Snapshots (from): {$fromCount}\n";
    echo "Snapshots (to):   {$toCount}\n\n";

    if ($fromCount <= 0) {
        throw new RuntimeException("No snapshots found for from_sync_run_id={$fromRunId}");
    }
    if ($toCount <= 0) {
        throw new RuntimeException("No snapshots found for to_sync_run_id={$toRunId}");
    }

    $computedAt = date('Y-m-d H:i:s');

    $pdo->beginTransaction();

    /**
     * IMPORTANT:
     * We use positional placeholders (?) because with native prepares (emulate_prepares=false),
     * PDO can throw HY093 when the same named placeholder is reused multiple times (especially in UNIONs).
     */
    $sql = "
        INSERT INTO eweb_inventory_deltas (
            from_sync_run_id,
            to_sync_run_id,
            sku,
            from_qoh,
            to_qoh,
            delta,
            computed_at
        )
        SELECT
            ? AS from_sync_run_id,
            ? AS to_sync_run_id,
            x.sku,
            x.from_qoh,
            x.to_qoh,
            (x.to_qoh - x.from_qoh) AS delta,
            ? AS computed_at
        FROM (
            SELECT
                t.sku AS sku,
                COALESCE(f.qoh, 0) AS from_qoh,
                t.qoh AS to_qoh
            FROM eweb_inventory_snapshots t
            LEFT JOIN eweb_inventory_snapshots f
                ON f.sync_run_id = ?
               AND f.sku = t.sku
            WHERE t.sync_run_id = ?

            UNION ALL

            SELECT
                f.sku AS sku,
                f.qoh AS from_qoh,
                COALESCE(t.qoh, 0) AS to_qoh
            FROM eweb_inventory_snapshots f
            LEFT JOIN eweb_inventory_snapshots t
                ON t.sync_run_id = ?
               AND t.sku = f.sku
            WHERE f.sync_run_id = ?
              AND t.sku IS NULL
        ) x
        ON DUPLICATE KEY UPDATE
            from_qoh = VALUES(from_qoh),
            to_qoh = VALUES(to_qoh),
            delta = VALUES(delta),
            computed_at = VALUES(computed_at)
    ";

    $stmt = $pdo->prepare($sql);

    // Parameter order must match the ? placeholders exactly:
    $params = [
        $fromRunId,        // SELECT ? AS from_sync_run_id
        $toRunId,          // SELECT ? AS to_sync_run_id
        $computedAt,       // SELECT ? AS computed_at

        $fromRunId,        // f.sync_run_id = ?
        $toRunId,          // WHERE t.sync_run_id = ?

        $toRunId,          // t.sync_run_id = ?
        $fromRunId,        // WHERE f.sync_run_id = ?
    ];

    $stmt->execute($params);

    $affected = $stmt->rowCount();

    $pdo->commit();

    echo "Upserted delta rows (affected): {$affected}\n";
    echo "DONE\n";
    exit(0);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
