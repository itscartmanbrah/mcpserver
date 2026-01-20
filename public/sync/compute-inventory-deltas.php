<?php
declare(strict_types=1);

// CLI invocation:
// php compute-inventory-deltas.php SYNC_TOKEN=... to_sync_run_id=23
if (PHP_SAPI === 'cli' && empty($_GET) && isset($argv)) {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}

set_time_limit(0);
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';

use App\Support\Db;

/* Access gate */
$token = (string)($_GET['SYNC_TOKEN'] ?? ($_GET['token'] ?? ''));
$expected = (string)(getenv('SYNC_TOKEN') ?: '');

if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    fwrite(STDERR, "Forbidden\n");
    exit(1);
}

$toRunId = (int)($_GET['to_sync_run_id'] ?? 0);
if ($toRunId <= 0) {
    http_response_code(400);
    exit("Missing or invalid to_sync_run_id\n");
}

echo "COMPUTE INVENTORY DELTAS\n";
echo "To run: {$toRunId}\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

$pdo = Db::pdo();

try {
    // Ensure target run exists and is success (optional but recommended)
    $stmt = $pdo->prepare("SELECT id, status FROM sync_runs WHERE id = ? LIMIT 1");
    $stmt->execute([$toRunId]);
    $toRun = $stmt->fetch();

    if (!$toRun) {
        throw new RuntimeException("sync_runs row not found for id={$toRunId}");
    }
    if (($toRun['status'] ?? '') !== 'success') {
        throw new RuntimeException("to_sync_run_id={$toRunId} is not success");
    }

    // Find most recent prior successful run that has snapshots
    $fromStmt = $pdo->prepare("
        SELECT sr.id
        FROM sync_runs sr
        WHERE sr.job_name = 'sync-active-items-latest'
          AND sr.status = 'success'
          AND sr.id < :to_id
          AND EXISTS (
              SELECT 1 FROM eweb_inventory_snapshots s
              WHERE s.sync_run_id = sr.id
          )
        ORDER BY sr.id DESC
        LIMIT 1
    ");
    $fromStmt->execute([':to_id' => $toRunId]);
    $fromRunId = (int)$fromStmt->fetchColumn();

    if ($fromRunId <= 0) {
        echo "No prior snapshot run found. Nothing to do.\n";
        exit(0);
    }

    echo "From run: {$fromRunId}\n";

    // Sanity counts
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM eweb_inventory_snapshots WHERE sync_run_id = ?");
    $cnt->execute([$toRunId]);
    $toCount = (int)$cnt->fetchColumn();

    $cnt->execute([$fromRunId]);
    $fromCount = (int)$cnt->fetchColumn();

    echo "Snapshots (from): {$fromCount}\n";
    echo "Snapshots (to):   {$toCount}\n\n";

    // Insert deltas by joining snapshots on sku
    // This will only compute deltas for SKUs present in BOTH runs.
    $pdo->beginTransaction();


$insert = $pdo->prepare("
    INSERT INTO eweb_inventory_deltas
        (from_sync_run_id, to_sync_run_id, sku, from_qoh, to_qoh, delta, computed_at)
    SELECT
        ? AS from_sync_run_id,
        ? AS to_sync_run_id,
        s_to.sku,
        s_from.qoh AS from_qoh,
        s_to.qoh   AS to_qoh,
        (s_to.qoh - s_from.qoh) AS delta,
        NOW() AS computed_at
    FROM eweb_inventory_snapshots s_to
    INNER JOIN eweb_inventory_snapshots s_from
        ON s_from.sku = s_to.sku
       AND s_from.sync_run_id = ?
    WHERE s_to.sync_run_id = ?
    ON DUPLICATE KEY UPDATE
        from_qoh = VALUES(from_qoh),
        to_qoh = VALUES(to_qoh),
        delta = VALUES(delta),
        computed_at = VALUES(computed_at)
");

$insert->execute([
    $fromRunId, // SELECT ? AS from_sync_run_id
    $toRunId,   // SELECT ? AS to_sync_run_id
    $fromRunId, // s_from.sync_run_id = ?
    $toRunId,   // s_to.sync_run_id = ?
]);


    $affected = (int)$insert->rowCount();
    $pdo->commit();

    echo "Upserted delta rows (affected): {$affected}\n";
    echo "DONE\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);

    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
