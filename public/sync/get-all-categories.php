<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';

use App\Support\Db;
use App\Eweb\EwebClientFactory;

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
 * Ensure we always have a list for repeating nodes.
 */
function ensureList(mixed $maybeList): array
{
    if ($maybeList === null) return [];
    if (is_array($maybeList)) return $maybeList;
    return [$maybeList];
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

try {
    // Use the factory client here for long-running calls (categories can be large)
    $wsdl = 'http://eweb.retailedgeconsultants.com/eWebService.svc?singleWsdl';
    $client = EwebClientFactory::make($wsdl);

    // 1) Fetch from eWeb
    $response = $client->GetAllCategories([
        'AuthenticationInfo' => $authInfo,
    ]);

    $result = $response->GetAllCategoriesResult ?? null;
    if ($result === null) {
        echo "No GetAllCategoriesResult returned.\n";
        exit;
    }

    // 2) Normalize and extract Category list
    $resultArr = soapToArray($result);
    $catsRaw = $resultArr['Category'] ?? null;
    $categories = ensureList($catsRaw);

    if (!$categories) {
        echo "No Category entries found.\n";
        exit;
    }

    // 3) Persist
    $pdo = Db::pdo();
    $pdo->beginTransaction();

    $catUpsertSql = "
        INSERT INTO eweb_categories (
            CategoryID, Name, Active, ParentID, UpdateDateTime, payload_json, created_at, updated_at
        ) VALUES (
            :CategoryID, :Name, :Active, :ParentID, :UpdateDateTime, :payload_json, NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            Name=VALUES(Name),
            Active=VALUES(Active),
            ParentID=VALUES(ParentID),
            UpdateDateTime=VALUES(UpdateDateTime),
            payload_json=VALUES(payload_json),
            updated_at=NOW()
    ";
    $catStmt = $pdo->prepare($catUpsertSql);

    $delDetailsSql = "DELETE FROM eweb_category_isd_details WHERE CategoryID = :CategoryID";
    $delStmt = $pdo->prepare($delDetailsSql);

    $detailInsertSql = "
        INSERT INTO eweb_category_isd_details (
            CategoryID, Field, Idx, Name, DetailType, ListName, DecimalPlaces,
            CaptionSeqNo, Caption, Suffix, AlwaysApplySuffix, BlankIfZero, ValuesToBlank, payload_json
        ) VALUES (
            :CategoryID, :Field, :Idx, :Name, :DetailType, :ListName, :DecimalPlaces,
            :CaptionSeqNo, :Caption, :Suffix, :AlwaysApplySuffix, :BlankIfZero, :ValuesToBlank, :payload_json
        )
    ";
    $detailStmt = $pdo->prepare($detailInsertSql);

    $catsUpserted = 0;
    $detailsInserted = 0;

    foreach ($categories as $c) {
        $c = soapToArray($c);

        $categoryId = isset($c['ID']) ? (int)$c['ID'] : 0;
        if ($categoryId <= 0) {
            continue;
        }

        $activeRaw = $c['Active'] ?? null;
        $active = null;
        if ($activeRaw !== null && $activeRaw !== '') {
            $active = (int)$activeRaw;
        }

        $name = isset($c['Name']) ? (string)$c['Name'] : null;
        $parentRaw = $c['ParentID'] ?? ($c['ParentId'] ?? null);
        $parentId = null;
        if ($parentRaw !== null && $parentRaw !== '') {
            $parentId = (int)$parentRaw;
        }

        $updated = ewebIsoToMysqlDatetimeOrNull(isset($c['UpdateDateTime']) ? (string)$c['UpdateDateTime'] : null);

        $catStmt->execute([
            ':CategoryID' => $categoryId,
            ':Name' => ($name === '' ? null : $name),
            ':Active' => $active,
            ':ParentID' => $parentId,
            ':UpdateDateTime' => $updated,
            ':payload_json' => json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $catsUpserted++;

        // ISDDetails -> CategoryISDDetail list
        $isdDetails = $c['ISDDetails']['CategoryISDDetail'] ?? null;
        $detailList = ensureList($isdDetails);

        // Replace details for this category
        $delStmt->execute([':CategoryID' => $categoryId]);

        foreach ($detailList as $d) {
            $d = soapToArray($d);

            $idxRaw = $d['Index'] ?? ($d['Idx'] ?? null);
            $idx = null;
            if ($idxRaw !== null && $idxRaw !== '') {
                $idx = (int)$idxRaw;
            }

            $detailStmt->execute([
                ':CategoryID' => $categoryId,
                ':Field' => isset($d['Field']) ? (string)$d['Field'] : null,
                ':Idx' => $idx,
                ':Name' => isset($d['Name']) ? (string)$d['Name'] : null,
                ':DetailType' => isset($d['DetailType']) ? (string)$d['DetailType'] : null,
                ':ListName' => isset($d['ListName']) ? (string)$d['ListName'] : null,
                ':DecimalPlaces' => (isset($d['DecimalPlaces']) && $d['DecimalPlaces'] !== '') ? (int)$d['DecimalPlaces'] : null,
                ':CaptionSeqNo' => (isset($d['CaptionSeqNo']) && $d['CaptionSeqNo'] !== '') ? (int)$d['CaptionSeqNo'] : null,
                ':Caption' => isset($d['Caption']) ? (string)$d['Caption'] : null,
                ':Suffix' => isset($d['Suffix']) ? (string)$d['Suffix'] : null,
                ':AlwaysApplySuffix' => isset($d['AlwaysApplySuffix']) ? (string)$d['AlwaysApplySuffix'] : null,
                ':BlankIfZero' => isset($d['BlankIfZero']) ? (string)$d['BlankIfZero'] : null,
                ':ValuesToBlank' => isset($d['ValuesToBlank']) ? (string)$d['ValuesToBlank'] : null,
                ':payload_json' => json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $detailsInserted++;
        }
    }

    $pdo->commit();

    echo "SYNC SUCCESS\n";
    echo "Categories upserted: {$catsUpserted}\n";
    echo "ISD detail rows inserted: {$detailsInserted}\n";

} catch (SoapFault $fault) {
    http_response_code(502);
    echo "GetAllCategories FAILED (SoapFault)\n";
    echo "Fault code: {$fault->faultcode}\n";
    echo "Fault string: {$fault->faultstring}\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . "\n";
}
