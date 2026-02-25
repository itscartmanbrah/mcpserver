<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';

$token = $_GET['SYNC_TOKEN'] ?? '';
if ($token === '' || $token !== getenv('SYNC_TOKEN')) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$csvPath = '/var/www/mcpserver/storage/import/Pandora MasterList.csv';
if (!is_readable($csvPath)) {
    http_response_code(400);
    echo "CSV not readable: {$csvPath}\n";
    exit;
}

function strip_bom(string $s): string {
    // UTF-8 BOM: EF BB BF
    return preg_replace('/^\xEF\xBB\xBF/', '', $s) ?? $s;
}

function norm_header(string $h): string {
    $h = strip_bom($h);
    $h = trim($h);
    // Lowercase, then remove all non-alphanumeric characters
    $h = strtolower($h);
    $h = preg_replace('/[^a-z0-9]+/', '', $h) ?? $h;
    return $h;
}

function header_index(array $headersNorm, array $aliases): int|false {
    foreach ($aliases as $a) {
        $aNorm = norm_header($a);
        $pos = array_search($aNorm, $headersNorm, true);
        if ($pos !== false) return $pos;
    }
    return false;
}

function parse_discontinued(?string $status): int {
    $s = strtolower(trim((string)$status));
    if ($s === '') return 0;
    if (str_contains($s, 'disc')) return 1;
    if ($s === 'y' || $s === 'yes' || $s === '1') return 1;
    return 0;
}

try {
    $pdo = App\Support\Db::pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $fh = fopen($csvPath, 'rb');
    if ($fh === false) {
        throw new RuntimeException("Failed to open: {$csvPath}");
    }

    $headerRow = fgetcsv($fh);
    if ($headerRow === false) {
        throw new RuntimeException("CSV appears empty (missing header row).");
    }

    $headersRaw = array_map(fn($h) => (string)$h, $headerRow);
    $headersNorm = array_map(fn($h) => norm_header((string)$h), $headersRaw);

    // Debug print so we can see what's being detected
    echo "Detected headers (raw): " . implode(" | ", $headersRaw) . "\n";
    echo "Detected headers (norm): " . implode(" | ", $headersNorm) . "\n";

    $col = [
        'design' => header_index($headersNorm, ['Design#','Design #','DesignNo','DesignNum','DesignNumber','Design']),
        'department' => header_index($headersNorm, ['Department']),
        'description' => header_index($headersNorm, ['Description']),
        'minqty' => header_index($headersNorm, ['Minimum Quantity','Min Quantity','MinimumQty','MinQty','Build To','BuildTo','BuildToLevel']),
        'status' => header_index($headersNorm, ['Status']),
        'notes' => header_index($headersNorm, ['Notes','Note']),
    ];

    foreach (['design','department','description','minqty'] as $req) {
        if ($col[$req] === false) {
            throw new RuntimeException("Missing required CSV header: {$req}");
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO pandora_master_list
            (design_num, department, description, min_qty, is_discontinued, notes)
        VALUES
            (:design_num, :department, :description, :min_qty, :is_discontinued, :notes)
        ON DUPLICATE KEY UPDATE
            department      = VALUES(department),
            description     = VALUES(description),
            min_qty         = VALUES(min_qty),
            is_discontinued = VALUES(is_discontinued),
            notes           = VALUES(notes),
            updated_at      = CURRENT_TIMESTAMP
    ");

    $rowsSeen = 0;
    $upserts = 0;
    $skipped = 0;
    $errors = 0;
    $rowNum = 1; // header row

    $pdo->beginTransaction();

    while (($row = fgetcsv($fh)) !== false) {
        $rowNum++;

        if (count($row) === 1 && trim((string)$row[0]) === '') {
            $skipped++;
            continue;
        }

        $design = trim((string)($row[$col['design']] ?? ''));
        if ($design === '') {
            $errors++;
            echo "[Row {$rowNum}] ERROR: missing Design\n";
            continue;
        }

        $dept = trim((string)($row[$col['department']] ?? ''));
        $desc = trim((string)($row[$col['description']] ?? ''));

        $minRaw = trim((string)($row[$col['minqty']] ?? '0'));
        if ($minRaw === '') $minRaw = '0';
        if (!preg_match('/^\d+$/', $minRaw)) {
            $errors++;
            echo "[Row {$rowNum}] ERROR: Minimum Quantity not a non-negative integer for Design={$design} (got '{$minRaw}')\n";
            continue;
        }
        $minQty = (int)$minRaw;

        $status = ($col['status'] !== false) ? (string)($row[$col['status']] ?? '') : '';
        $notes  = ($col['notes']  !== false) ? trim((string)($row[$col['notes']] ?? '')) : '';

        $isDisc = parse_discontinued($status);

        $stmt->execute([
            ':design_num' => $design,
            ':department' => $dept,
            ':description' => $desc,
            ':min_qty' => $minQty,
            ':is_discontinued' => $isDisc,
            ':notes' => ($notes === '' ? null : $notes),
        ]);

        $upserts++;
        $rowsSeen++;
    }

    $pdo->commit();
    fclose($fh);

    echo "OK\n";
    echo "CSV: {$csvPath}\n";
    echo "Rows processed: {$rowsSeen}\n";
    echo "Upserts: {$upserts}\n";
    echo "Skipped: {$skipped}\n";
    echo "Errors: {$errors}\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo "FATAL: " . $e->getMessage() . "\n";
}
