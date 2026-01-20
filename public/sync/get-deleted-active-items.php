<?php
declare(strict_types=1);

// Allow CLI invocation:
// php file.php SYNC_TOKEN=... batch=...
if (PHP_SAPI === 'cli' && empty($_GET) && isset($argv)) {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}


set_time_limit(0);
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';

use App\Support\Db;

// -----------------------
// Basic sync access gate
// -----------------------
$token = (string)($_GET['SYNC_TOKEN'] ?? ($_GET['token'] ?? ''));
$expected = (string)(getenv('SYNC_TOKEN') ?: '');

if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    exit("Forbidden\n");
}

/* =========================
   Helpers (guarded to avoid redeclare when included by a runner)
   ========================= */

if (!function_exists('soapToArray')) {
    function soapToArray(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = soapToArray($v);
            }
            return $out;
        }

        if (is_object($value)) {
            $out = [];
            foreach (get_object_vars($value) as $k => $v) {
                $out[$k] = soapToArray($v);
            }
            return $out;
        }

        return $value;
    }
}

if (!function_exists('ensureList')) {
    function ensureList(mixed $v): array
    {
        if ($v === null) return [];
        if (is_array($v)) {
            $keys = array_keys($v);
            $isList = ($keys === range(0, count($keys) - 1));
            return $isList ? $v : [$v];
        }
        return [$v];
    }
}

if (!function_exists('ewebIsoToMysqlDatetimeOrNull')) {
    function ewebIsoToMysqlDatetimeOrNull(?string $iso): ?string
    {
        if ($iso === null) return null;

        $iso = trim($iso);
        if ($iso === '' || str_starts_with($iso, '0001-01-01')) {
            return null;
        }

        $iso = rtrim($iso, 'Z');
        $iso = str_replace('T', ' ', $iso);

        // Normalize fractional seconds to 3 digits if present
        if (preg_match('/\.(\d{1,7})$/', $iso, $m)) {
            $frac = substr(str_pad($m[1], 3, '0', STR_PAD_RIGHT), 0, 3);
            $iso = preg_replace('/\.\d{1,7}$/', '.' . $frac, $iso);
        }

        return $iso;
    }
}

if (!function_exists('extractDeletedItems')) {
    /**
     * Extract deleted item rows from SOAP response.
     * Common shapes:
     * - ArrayOfDeletedItem => ['DeletedItem' => [...]]
     * - ['DeletedItem' => single]
     * - direct list
     */
    function extractDeletedItems(mixed $result): array
    {
        $arr = soapToArray($result);

        if (is_array($arr) && array_key_exists('DeletedItem', $arr)) {
            return ensureList($arr['DeletedItem']);
        }

        if (is_array($arr)) {
            $keys = array_keys($arr);
            $isList = ($keys === range(0, count($keys) - 1));
            return $isList ? $arr : [];
        }

        return [];
    }
}

/* =========================
   Main
   ========================= */

try {
    $pdo = Db::pdo();

    echo "Fetching deleted active items...\n";

    // NOTE: method name must match your WSDL; you said it's working now.
    $response = $soapClient->GetAllDeletedItems([
        'AuthenticationInfo' => $authInfo,
    ]);

    $result = $response->GetAllDeletedItemsResult ?? null;
    if ($result === null) {
        echo "No GetAllDeletedItemsResult returned.\n";
        exit;
    }

    $deletedItems = extractDeletedItems($result);
    $total = count($deletedItems);

    echo "Deleted items returned: {$total}\n";

    // Mark as deleted (assumes you added these columns)
    $stmtMark = $pdo->prepare("
        UPDATE eweb_active_items
        SET is_deleted = 1,
            deleted_at = :deleted_at
        WHERE SKU = :sku
    ");

    $marked = 0;
    $skippedNoSku = 0;

    foreach ($deletedItems as $row) {
        $sku = (string)($row['SKU'] ?? '');
        if ($sku === '') {
            $skippedNoSku++;
            continue;
        }

        $deletedAt = ewebIsoToMysqlDatetimeOrNull($row['DeletedDate'] ?? null)
            ?? date('Y-m-d H:i:s');

        $stmtMark->execute([
            ':sku' => $sku,
            ':deleted_at' => $deletedAt,
        ]);

        $marked++;
    }

    echo "Deleted items marked: {$marked}\n";
    echo "Skipped (missing SKU): {$skippedNoSku}\n";
    echo "DONE\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . "\n";
}
