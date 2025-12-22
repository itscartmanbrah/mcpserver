<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';
// Db is autoloaded by Bootstrap.php via your App\* loader.
use App\Support\Db;



/**
 * Sync: GetAllVendors -> eweb_vendors
 *
 * Guarded by SYNC_TOKEN query param.
 * Example:
 *   /public/sync/get-all-vendors.php?SYNC_TOKEN=yourtoken
 */

function normalizeSoapValue(mixed $value): mixed {
    if ($value instanceof stdClass) {
        $value = (array)$value;
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = normalizeSoapValue($v);
        }
        return $out;
    }
    return $value;
}

function parseIsoDateTimeToMysql(?string $iso): ?string {
    if ($iso === null || $iso === '') return null;
    // Example: 2025-12-06T01:16:38.887Z
    try {
        $dt = new DateTimeImmutable($iso);
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

try {
    // ---- gate ----
    $token = $_GET['SYNC_TOKEN'] ?? '';
    $expected = getenv('SYNC_TOKEN') ?: '';
    if ($expected === '' || !hash_equals($expected, (string)$token)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }

    // ---- call SOAP ----
    $payload = [
        'AuthenticationInfo' => $authInfo,
    ];

    // If your WSDL method is GetAllVendors, keep this:
    $response = $soapClient->GetAllVendors($payload);
    $result = $response->GetAllVendorsResult ?? $response;

    $normalized = normalizeSoapValue($result);

    // Expecting: ['Vendor' => [ ...vendors... ]]
    $vendors = $normalized['Vendor'] ?? [];
    if ($vendors instanceof stdClass) {
        $vendors = [$vendors];
    }
    if (!is_array($vendors)) {
        $vendors = [];
    }

    // Ensure list wrapping when SOAP returns a single object
    if (isset($vendors['ID']) || isset($vendors['Name']) || isset($vendors['VendorID'])) {
        $vendors = [$vendors];
    }

    $pdo = Db::pdo();
    $pdo->beginTransaction();

    $upsertSql = "
        INSERT INTO eweb_vendors
            (VendorID, ISSCode, Name, Active, UpdateDateTime, payload_json)
        VALUES
            (:VendorID, :ISSCode, :Name, :Active, :UpdateDateTime, :payload_json)
        ON DUPLICATE KEY UPDATE
            ISSCode = VALUES(ISSCode),
            Name = VALUES(Name),
            Active = VALUES(Active),
            UpdateDateTime = VALUES(UpdateDateTime),
            payload_json = VALUES(payload_json)
    ";
    $stmt = $pdo->prepare($upsertSql);

    $upserted = 0;
    $skippedMissingId = 0;
    $blankIds = 0;

    foreach ($vendors as $v) {
        if ($v instanceof stdClass) $v = (array)$v;

        // Your SOAP uses ID for vendor id
        $vendorId = isset($v['ID']) ? trim((string)$v['ID']) : '';

        // Track blanks (for sanity)
        if ($vendorId === '') {
            $skippedMissingId++;
            $blankIds++;
            continue;
        }

        $iss = isset($v['ISSCode']) ? trim((string)$v['ISSCode']) : null;
        $name = isset($v['Name']) ? (string)$v['Name'] : null;

        // Active sometimes blank in your samples
        $activeRaw = $v['Active'] ?? null;
        $active = null;
        if ($activeRaw !== null && $activeRaw !== '') {
            $active = (int)$activeRaw;
        }

        $updated = parseIsoDateTimeToMysql(isset($v['UpdateDateTime']) ? (string)$v['UpdateDateTime'] : null);
        $payloadJson = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt->execute([
            ':VendorID' => $vendorId,
            ':ISSCode' => ($iss === '' ? null : $iss),
            ':Name' => ($name === '' ? null : $name),
            ':Active' => $active,
            ':UpdateDateTime' => $updated,
            ':payload_json' => $payloadJson,
        ]);

        $upserted++;
    }

    $pdo->commit();

    echo "GetAllVendors SYNC SUCCESS\n";
    echo "Upserted: {$upserted}\n";
    echo "Skipped (missing ID): {$skippedMissingId}\n\n";
    echo "Total: " . count($vendors) . "\n";
    echo "Blank_ids: {$blankIds}\n";

} catch (SoapFault $fault) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(502);
    echo "GetAllVendors FAILED (SoapFault)\n";
    echo "Fault code: {$fault->faultcode}\n";
    echo "Fault string: {$fault->faultstring}\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo "GetAllVendors FAILED\n";
    echo get_class($e) . ": " . $e->getMessage() . "\n";
}
