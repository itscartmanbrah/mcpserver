<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';

use App\Support\Db;

// -----------------------
// Basic sync access gate
// -----------------------
$token = (string)($_GET['SYNC_TOKEN'] ?? '');
$expected = (string)(getenv('SYNC_TOKEN') ?: '');

if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    exit("Forbidden\n");
}


/**
 * Convert SOAP stdClass / arrays into plain PHP arrays recursively.
 */
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

/**
 * Convert eWeb ISO-like datetime strings to MySQL DATETIME(3) or NULL.
 * Returns NULL for sentinel "0001-01-01..." values which MySQL cannot store.
 */
function ewebIsoToMysqlDatetimeOrNull(?string $iso): ?string
{
    if ($iso === null) return null;

    $iso = trim($iso);
    if ($iso === '' || str_starts_with($iso, '0001-01-01')) {
        return null;
    }

    $iso = rtrim($iso, 'Z');
    $iso = str_replace('T', ' ', $iso);

    // Normalize fractional seconds to 3 digits if present with 1–2 digits
    if (preg_match('/\.(\d{1,2})$/', $iso, $m)) {
        $frac = str_pad($m[1], 3, '0', STR_PAD_RIGHT);
        $iso = preg_replace('/\.\d{1,2}$/', '.' . $frac, $iso);
    }

    return $iso;
}

/**
 * Ensure we always have a list for repeating nodes.
 */
function ensureList(mixed $maybeList): array
{
    if ($maybeList === null) return [];
    if (is_array($maybeList)) return $maybeList;
    return [$maybeList];
}

try {
    // 1) Fetch from eWeb
    $response = $soapClient->GetAllBrands([
        'AuthenticationInfo' => $authInfo,
    ]);

    $result = $response->GetAllBrandsResult ?? null;
    if ($result === null) {
        echo "No GetAllBrandsResult returned.\n";
        exit;
    }

    // 2) Normalize and extract Brand list
    $resultArr = soapToArray($result);

    // Your tester output shows: stdClass { Brand: [ ... ] }
    $brandsRaw = $resultArr['Brand'] ?? null;
    $brands = ensureList($brandsRaw);

    if (!$brands) {
        echo "No Brand entries found.\n";
        exit;
    }

    // 3) Persist
    $pdo = Db::pdo();
    $pdo->beginTransaction();

    $sql = "
        INSERT INTO eweb_brands (
            BrandID, Name, Active, UpdateDateTime, payload_json, created_at, updated_at
        ) VALUES (
            :BrandID, :Name, :Active, :UpdateDateTime, :payload_json, NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            Name=VALUES(Name),
            Active=VALUES(Active),
            UpdateDateTime=VALUES(UpdateDateTime),
            payload_json=VALUES(payload_json),
            updated_at=NOW()
    ";

    $stmt = $pdo->prepare($sql);

    $count = 0;

    foreach ($brands as $b) {
        // Each element might still be an object if something slips through
        $b = soapToArray($b);

        $brandId = (string)($b['ID'] ?? '');
        if ($brandId === '') {
            // Skip malformed rows safely
            continue;
        }

        $activeRaw = $b['Active'] ?? null;
        $active = null;
        if ($activeRaw !== null && $activeRaw !== '') {
            $active = (int)$activeRaw; // typically 1/0
        }

        $row = [
            'BrandID'       => $brandId,
            'Name'          => isset($b['Name']) ? (string)$b['Name'] : null,
            'Active'        => $active,
            'UpdateDateTime'=> ewebIsoToMysqlDatetimeOrNull(isset($b['UpdateDateTime']) ? (string)$b['UpdateDateTime'] : null),
            'payload_json'  => json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $stmt->execute([
            ':BrandID'        => $row['BrandID'],
            ':Name'           => $row['Name'],
            ':Active'         => $row['Active'],
            ':UpdateDateTime' => $row['UpdateDateTime'],
            ':payload_json'   => $row['payload_json'],
        ]);

        $count++;
    }

    $pdo->commit();

    echo "SYNC SUCCESS\n";
    echo "Brands upserted: {$count}\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . "\n";
}
