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

/* =========================
   Access gate
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
   Helpers
   ========================= */
function soapToArray(mixed $value): mixed
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) $out[$k] = soapToArray($v);
        return $out;
    }
    if (is_object($value)) {
        $out = [];
        foreach (get_object_vars($value) as $k => $v) $out[$k] = soapToArray($v);
        return $out;
    }
    return $value;
}

function ensureList(mixed $v): array
{
    if ($v === null) return [];
    if (is_array($v)) {
        // If associative array (single object), wrap it
        $keys = array_keys($v);
        $isList = ($keys === range(0, count($keys) - 1));
        return $isList ? $v : [$v];
    }
    return [$v];
}

/**
 * Convert eWeb ISO-like datetime strings to MySQL DATETIME or NULL.
 * Treats 1753-01-01 and 0001-01-01 as sentinel values.
 */
function ewebIsoToMysqlDatetimeOrNull(?string $iso): ?string
{
    if ($iso === null) return null;
    $iso = trim($iso);
    if ($iso === '') return null;

    if (str_starts_with($iso, '1753-01-01') || str_starts_with($iso, '0001-01-01')) {
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

/**
 * Extract customers array from SOAP response.
 * Common shapes:
 * - ArrayOfCustomer => ['Customer' => [...]]
 * - ['Customer' => singleCustomer]
 * - Single customer associative array with 'ID'
 */
function extractCustomers(mixed $result): array
{
    $arr = soapToArray($result);

    if (is_array($arr) && array_key_exists('Customer', $arr)) {
        return ensureList($arr['Customer']);
    }

    // Some SOAP libs may return a single customer object directly
    if (is_array($arr) && isset($arr['ID'])) {
        return [$arr];
    }

    // If it's already a list
    if (is_array($arr)) {
        $keys = array_keys($arr);
        $isList = ($keys === range(0, count($keys) - 1));
        return $isList ? $arr : [];
    }

    return [];
}

/* =========================
   Main
   ========================= */
try {
    $pdo = Db::pdo();

    $batchSize = (int)($_GET['batch'] ?? 200);
    if ($batchSize <= 0) $batchSize = 200;

    /* =========================
       Prepared statement
       ========================= */
    $stmtCustomer = $pdo->prepare("
        INSERT INTO eweb_customers (
            `ID`,
            `FirstName`, `MiddleName`, `LastName`,
            `Title`, `Suffix`,
            `BirthDate`, `DateEntered`, `UpdateDateTime`,
            `LoyaltyLevel`, `LoyaltyPoints`, `LoyaltyPointValue`,
            `PriceGroupId`,
            `Addresses`, `Emails`, `PhoneNums`,
            `payload_json`,
            `created_at`, `updated_at`
        ) VALUES (
            :id,
            :first_name, :middle_name, :last_name,
            :title, :suffix,
            :birth_date, :date_entered, :update_datetime,
            :loyalty_level, :loyalty_points, :loyalty_point_value,
            :price_group_id,
            :addresses, :emails, :phones,
            :payload_json,
            NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            `FirstName` = VALUES(`FirstName`),
            `MiddleName` = VALUES(`MiddleName`),
            `LastName` = VALUES(`LastName`),
            `Title` = VALUES(`Title`),
            `Suffix` = VALUES(`Suffix`),
            `BirthDate` = VALUES(`BirthDate`),
            `DateEntered` = VALUES(`DateEntered`),
            `UpdateDateTime` = VALUES(`UpdateDateTime`),
            `LoyaltyLevel` = VALUES(`LoyaltyLevel`),
            `LoyaltyPoints` = VALUES(`LoyaltyPoints`),
            `LoyaltyPointValue` = VALUES(`LoyaltyPointValue`),
            `PriceGroupId` = VALUES(`PriceGroupId`),
            `Addresses` = VALUES(`Addresses`),
            `Emails` = VALUES(`Emails`),
            `PhoneNums` = VALUES(`PhoneNums`),
            `payload_json` = VALUES(`payload_json`),
            `updated_at` = NOW()
    ");

    /* =========================
       Fetch customers
       ========================= */
    $response = $soapClient->GetAllCustomers([
        'AuthenticationInfo' => $authInfo,
    ]);

    $result = $response->GetAllCustomersResult ?? null;
    if ($result === null) {
        exit("No GetAllCustomersResult returned\n");
    }

    $customers = extractCustomers($result);
    $total = count($customers);

    echo "Customers returned: {$total}\n";
    echo "Batch size: {$batchSize}\n\n";

    /* =========================
       Persist
       ========================= */
    $pdo->beginTransaction();
    $inBatch = 0;

    $saved = 0;
    $skippedNoId = 0;

    foreach ($customers as $i => $custRaw) {
        $cust = soapToArray($custRaw);

        $id = (string)($cust['ID'] ?? '');
        if ($id === '') {
            $skippedNoId++;
            continue;
        }

        // JSON columns: keep structure exactly as returned, but ensure valid JSON.
        $addressesJson = null;
        if (array_key_exists('Addresses', $cust)) {
            $addressesJson = json_encode($cust['Addresses'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $emailsJson = null;
        if (array_key_exists('Emails', $cust)) {
            $emailsJson = json_encode($cust['Emails'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $phonesJson = null;
        if (array_key_exists('PhoneNums', $cust)) {
            $phonesJson = json_encode($cust['PhoneNums'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $payloadJson = json_encode(
            $cust,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $stmtCustomer->execute([
            ':id' => $id,
            ':first_name' => $cust['FirstName'] ?? null,
            ':middle_name' => $cust['MiddleName'] ?? null,
            ':last_name' => $cust['LastName'] ?? null,
            ':title' => $cust['Title'] ?? null,
            ':suffix' => $cust['Suffix'] ?? null,

            ':birth_date' => ewebIsoToMysqlDatetimeOrNull($cust['BirthDate'] ?? null),
            ':date_entered' => ewebIsoToMysqlDatetimeOrNull($cust['DateEntered'] ?? null),
            ':update_datetime' => ewebIsoToMysqlDatetimeOrNull($cust['UpdateDateTime'] ?? null),

            ':loyalty_level' => $cust['LoyaltyLevel'] ?? null,
            ':loyalty_points' => isset($cust['LoyaltyPoints']) ? (int)$cust['LoyaltyPoints'] : null,
            ':loyalty_point_value' => isset($cust['LoyaltyPointValue']) ? (string)$cust['LoyaltyPointValue'] : null,
            ':price_group_id' => isset($cust['PriceGroupId']) ? (int)$cust['PriceGroupId'] : null,

            ':addresses' => $addressesJson,
            ':emails' => $emailsJson,
            ':phones' => $phonesJson,

            ':payload_json' => $payloadJson,
        ]);

        $saved++;
        $inBatch++;

        if ($inBatch >= $batchSize) {
            $pdo->commit();
            $pdo->beginTransaction();
            $inBatch = 0;
            echo "Progress: {$saved} / {$total}\n";
        }
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    echo "\nSYNC SUCCESS\n";
    echo "Customers saved: {$saved}\n";
    echo "Skipped (missing ID): {$skippedNoId}\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . "\n";
}
