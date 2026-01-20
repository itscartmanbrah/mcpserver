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
use App\Eweb\EwebClientFactory;

// -----------------------
// Basic sync access gate
// -----------------------
$token = (string)($_GET['SYNC_TOKEN'] ?? ($_GET['token'] ?? ''));
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

    // Normalize fractional seconds to 3 digits
    if (preg_match('/\.(\d{1,7})$/', $iso, $m)) {
        $frac = substr(str_pad($m[1], 3, '0', STR_PAD_RIGHT), 0, 3);
        $iso = preg_replace('/\.\d{1,7}$/', '.' . $frac, $iso);
    }

    return $iso;
}

function ensureList(mixed $maybeList): array
{
    if ($maybeList === null) return [];
    if (is_array($maybeList)) return $maybeList;
    return [$maybeList];
}

function nullIfBlank(mixed $v): ?string
{
    if ($v === null) return null;
    $s = trim((string)$v);
    return $s === '' ? null : $s;
}

function extractActiveItemsArray(mixed $result): array
{
    $arr = soapToArray($result);

    if (is_array($arr) && array_key_exists('ActiveItem', $arr)) {
        return ensureList($arr['ActiveItem']);
    }

    if (is_array($arr)) {
        $keys = array_keys($arr);
        $isList = $keys === range(0, count($keys) - 1);
        return $isList ? $arr : [];
    }

    return [];
}

try {
    $pdo = Db::pdo();

    $batchSize = (int)($_GET['batch'] ?? 50);
    if ($batchSize <= 0) $batchSize = 50;

    // IMPORTANT: fresh client for the heavy call
    // Bootstrap created $wsdl; if not, fall back to env or hardcoded.
    $wsdlLocal = $wsdl ?? (string)(getenv('EWEB_WSDL') ?: 'http://eweb.retailedgeconsultants.com/eWebService.svc?singleWsdl');
    $soapClientLocal = EwebClientFactory::make($wsdlLocal);

    // Prepare statements
    $stmtUpsertItem = $pdo->prepare("
        INSERT INTO eweb_active_items (
            SKU, StockNum, OldKey, OldBarcode, Barcode,
            BrandID, CategoryID, VendorID, DesignNum, RealDesignNum, WebMenuCode,
            Description, MarketingDescription, ShortMarketingDescription, CustomTitle, CustomDescription,
            ID1, ID2, ID3, ID4,
            Cost, Price, CurrentPrice, RetailPrice, RetailPrice2, RetailPrice3, RetailPrice4, RetailerRRP1, RetailerRRP2,
            SpecialPrice, CataloguePrice,
            CataloguePriceStart, CataloguePriceEnd, SpecialPriceStart, SpecialPriceEnd,
            LastDateIn, UpdateDateTime,
            TotalAvailQOH, ItemWeight, WeightUnit, UOM, Location,
            WebOptionBoolean1, WebOptionBoolean2, WebOptionBoolean3, WebOptionBoolean4,
            WebOptionBoolean5, WebOptionBoolean6, WebOptionBoolean7, WebOptionBoolean8,
            PriceGroupPrices, payload_json,
            is_deleted, deleted_at,
            created_at, updated_at
        ) VALUES (
            :SKU, :StockNum, :OldKey, :OldBarcode, :Barcode,
            :BrandID, :CategoryID, :VendorID, :DesignNum, :RealDesignNum, :WebMenuCode,
            :Description, :MarketingDescription, :ShortMarketingDescription, :CustomTitle, :CustomDescription,
            :ID1, :ID2, :ID3, :ID4,
            :Cost, :Price, :CurrentPrice, :RetailPrice, :RetailPrice2, :RetailPrice3, :RetailPrice4, :RetailerRRP1, :RetailerRRP2,
            :SpecialPrice, :CataloguePrice,
            :CataloguePriceStart, :CataloguePriceEnd, :SpecialPriceStart, :SpecialPriceEnd,
            :LastDateIn, :UpdateDateTime,
            :TotalAvailQOH, :ItemWeight, :WeightUnit, :UOM, :Location,
            :WebOptionBoolean1, :WebOptionBoolean2, :WebOptionBoolean3, :WebOptionBoolean4,
            :WebOptionBoolean5, :WebOptionBoolean6, :WebOptionBoolean7, :WebOptionBoolean8,
            :PriceGroupPrices, :payload_json,
            0, NULL,
            NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            StockNum=VALUES(StockNum),
            OldKey=VALUES(OldKey),
            OldBarcode=VALUES(OldBarcode),
            Barcode=VALUES(Barcode),
            BrandID=VALUES(BrandID),
            CategoryID=VALUES(CategoryID),
            VendorID=VALUES(VendorID),
            DesignNum=VALUES(DesignNum),
            RealDesignNum=VALUES(RealDesignNum),
            WebMenuCode=VALUES(WebMenuCode),
            Description=VALUES(Description),
            MarketingDescription=VALUES(MarketingDescription),
            ShortMarketingDescription=VALUES(ShortMarketingDescription),
            CustomTitle=VALUES(CustomTitle),
            CustomDescription=VALUES(CustomDescription),
            ID1=VALUES(ID1),
            ID2=VALUES(ID2),
            ID3=VALUES(ID3),
            ID4=VALUES(ID4),
            Cost=VALUES(Cost),
            Price=VALUES(Price),
            CurrentPrice=VALUES(CurrentPrice),
            RetailPrice=VALUES(RetailPrice),
            RetailPrice2=VALUES(RetailPrice2),
            RetailPrice3=VALUES(RetailPrice3),
            RetailPrice4=VALUES(RetailPrice4),
            RetailerRRP1=VALUES(RetailerRRP1),
            RetailerRRP2=VALUES(RetailerRRP2),
            SpecialPrice=VALUES(SpecialPrice),
            CataloguePrice=VALUES(CataloguePrice),
            CataloguePriceStart=VALUES(CataloguePriceStart),
            CataloguePriceEnd=VALUES(CataloguePriceEnd),
            SpecialPriceStart=VALUES(SpecialPriceStart),
            SpecialPriceEnd=VALUES(SpecialPriceEnd),
            LastDateIn=VALUES(LastDateIn),
            UpdateDateTime=VALUES(UpdateDateTime),
            TotalAvailQOH=VALUES(TotalAvailQOH),
            ItemWeight=VALUES(ItemWeight),
            WeightUnit=VALUES(WeightUnit),
            UOM=VALUES(UOM),
            Location=VALUES(Location),
            WebOptionBoolean1=VALUES(WebOptionBoolean1),
            WebOptionBoolean2=VALUES(WebOptionBoolean2),
            WebOptionBoolean3=VALUES(WebOptionBoolean3),
            WebOptionBoolean4=VALUES(WebOptionBoolean4),
            WebOptionBoolean5=VALUES(WebOptionBoolean5),
            WebOptionBoolean6=VALUES(WebOptionBoolean6),
            WebOptionBoolean7=VALUES(WebOptionBoolean7),
            WebOptionBoolean8=VALUES(WebOptionBoolean8),
            PriceGroupPrices=VALUES(PriceGroupPrices),
            payload_json=VALUES(payload_json),
            is_deleted=0,
            deleted_at=NULL,
            updated_at=NOW()
    ");

    $stmtChkBrand  = $pdo->prepare("SELECT 1 FROM eweb_brands WHERE BrandID = :id LIMIT 1");
    $stmtChkVendor = $pdo->prepare("SELECT 1 FROM eweb_vendors WHERE VendorID = :id LIMIT 1");

    $stmtDelIsd = $pdo->prepare("DELETE FROM eweb_active_item_isds WHERE item_sku = :sku");
    $stmtInsIsd = $pdo->prepare("
        INSERT INTO eweb_active_item_isds (item_sku, `Index`, `Name`, `Value`)
        VALUES (:item_sku, :idx, :name, :val)
    ");

    $stmtDelImg = $pdo->prepare("DELETE FROM eweb_active_item_images WHERE item_sku = :sku");
    $stmtInsImg = $pdo->prepare("
        INSERT INTO eweb_active_item_images (item_sku, `Index`, URL, Width, Height, UpdateDateTime)
        VALUES (:item_sku, :idx, :url, :w, :h, :udt)
    ");

    // -----------------------
    // Fetch ALL active items with retry
    // -----------------------
    $attempts = 2;
    $response = null;
    $lastErr = null;

    for ($a = 1; $a <= $attempts; $a++) {
        try {
            $response = $soapClientLocal->GetAllActiveItems([
                'AuthenticationInfo' => $authInfo,
            ]);
            $lastErr = null;
            break;
        } catch (Throwable $e) {
            $lastErr = $e;
            if ($a < $attempts) {
                sleep(2);
                // Rebuild client and retry
                $soapClientLocal = EwebClientFactory::make($wsdlLocal);
                continue;
            }
        }
    }

    if ($lastErr !== null) {
        throw $lastErr;
    }

    $result = $response->GetAllActiveItemsResult ?? null;
    if ($result === null) {
        echo "No GetAllActiveItemsResult returned.\n";
        exit;
    }

    $items = extractActiveItemsArray($result);
    if (!$items) {
        echo "No ActiveItem rows found in response.\n";
        exit;
    }

    $total = count($items);
    echo "Items returned: {$total}\n";
    echo "Batch size: {$batchSize}\n\n";

    $totalUpserted = 0;
    $totalIsds = 0;
    $totalImages = 0;
    $skippedNoSku = 0;

    $pdo->beginTransaction();
    $inBatch = 0;

    foreach ($items as $i => $itemRaw) {
        $item = soapToArray($itemRaw);

        $sku = (string)($item['SKU'] ?? '');
        if ($sku === '') {
            $skippedNoSku++;
            continue;
        }

        // ISDs
        $isdRows = [];
        if (isset($item['ISDs']['ItemISD'])) {
            foreach (ensureList($item['ISDs']['ItemISD']) as $isd) {
                $isdRows[] = [
                    'Index' => isset($isd['Index']) ? (int)$isd['Index'] : null,
                    'Name'  => isset($isd['Name']) ? (string)$isd['Name'] : null,
                    'Value' => isset($isd['Value']) ? (string)$isd['Value'] : null,
                ];
            }
        }

        // Images
        $imageRows = [];
        if (isset($item['Images']['ItemImage'])) {
            foreach (ensureList($item['Images']['ItemImage']) as $img) {
                $imageRows[] = [
                    'Index'          => isset($img['Index']) ? (int)$img['Index'] : null,
                    'URL'            => isset($img['URL']) ? (string)$img['URL'] : null,
                    'Width'          => isset($img['Width']) ? (int)$img['Width'] : null,
                    'Height'         => isset($img['Height']) ? (int)$img['Height'] : null,
                    'UpdateDateTime' => ewebIsoToMysqlDatetimeOrNull($img['UpdateDateTime'] ?? null),
                ];
            }
        }

        $payloadJson = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $main = [
            'SKU' => $sku,

            'StockNum'   => isset($item['StockNum']) ? (int)$item['StockNum'] : null,
            'OldKey'     => $item['OldKey'] ?? null,
            'OldBarcode' => $item['OldBarcode'] ?? null,
            'Barcode'    => $item['Barcode'] ?? null,

            'BrandID'    => nullIfBlank($item['BrandID'] ?? null),
            'CategoryID' => isset($item['CategoryID']) ? (int)$item['CategoryID'] : null,
            'VendorID'   => nullIfBlank($item['VendorID'] ?? null),

            'DesignNum'     => $item['DesignNum'] ?? null,
            'RealDesignNum' => $item['RealDesignNum'] ?? null,
            'WebMenuCode'   => $item['WebMenuCode'] ?? null,

            'Description'               => $item['Description'] ?? null,
            'MarketingDescription'      => $item['MarketingDescription'] ?? null,
            'ShortMarketingDescription' => $item['ShortMarketingDescription'] ?? null,
            'CustomTitle'               => $item['CustomTitle'] ?? null,
            'CustomDescription'         => $item['CustomDescription'] ?? null,

            'ID1' => $item['ID1'] ?? null,
            'ID2' => $item['ID2'] ?? null,
            'ID3' => $item['ID3'] ?? null,
            'ID4' => $item['ID4'] ?? null,

            'Cost'         => isset($item['Cost']) ? (string)$item['Cost'] : null,
            'Price'        => isset($item['Price']) ? (string)$item['Price'] : null,
            'CurrentPrice' => isset($item['CurrentPrice']) ? (string)$item['CurrentPrice'] : null,
            'RetailPrice'  => isset($item['RetailPrice']) ? (string)$item['RetailPrice'] : null,
            'RetailPrice2' => isset($item['RetailPrice2']) ? (string)$item['RetailPrice2'] : null,
            'RetailPrice3' => isset($item['RetailPrice3']) ? (string)$item['RetailPrice3'] : null,
            'RetailPrice4' => isset($item['RetailPrice4']) ? (string)$item['RetailPrice4'] : null,
            'RetailerRRP1' => isset($item['RetailerRRP1']) ? (string)$item['RetailerRRP1'] : null,
            'RetailerRRP2' => isset($item['RetailerRRP2']) ? (string)$item['RetailerRRP2'] : null,

            'SpecialPrice'   => isset($item['SpecialPrice']) ? (string)$item['SpecialPrice'] : null,
            'CataloguePrice' => isset($item['CataloguePrice']) ? (string)$item['CataloguePrice'] : null,

            // kept as-is (often dateTimes; if your columns are VARCHAR this is fine)
            'CataloguePriceStart' => $item['CataloguePriceStart'] ?? null,
            'CataloguePriceEnd'   => $item['CataloguePriceEnd'] ?? null,
            'SpecialPriceStart'   => $item['SpecialPriceStart'] ?? null,
            'SpecialPriceEnd'     => $item['SpecialPriceEnd'] ?? null,

            'LastDateIn'     => ewebIsoToMysqlDatetimeOrNull($item['LastDateIn'] ?? null),
            'UpdateDateTime' => ewebIsoToMysqlDatetimeOrNull($item['UpdateDateTime'] ?? null),

            'TotalAvailQOH' => isset($item['TotalAvailQOH']) ? (string)$item['TotalAvailQOH'] : null,
            'ItemWeight'    => isset($item['ItemWeight']) ? (string)$item['ItemWeight'] : null,
            'WeightUnit'    => $item['WeightUnit'] ?? null,
            'UOM'           => $item['UOM'] ?? null,
            'Location'      => $item['Location'] ?? null,

            'WebOptionBoolean1' => $item['WebOptionBoolean1'] ?? null,
            'WebOptionBoolean2' => $item['WebOptionBoolean2'] ?? null,
            'WebOptionBoolean3' => $item['WebOptionBoolean3'] ?? null,
            'WebOptionBoolean4' => $item['WebOptionBoolean4'] ?? null,
            'WebOptionBoolean5' => $item['WebOptionBoolean5'] ?? null,
            'WebOptionBoolean6' => $item['WebOptionBoolean6'] ?? null,
            'WebOptionBoolean7' => $item['WebOptionBoolean7'] ?? null,
            'WebOptionBoolean8' => $item['WebOptionBoolean8'] ?? null,

            'PriceGroupPrices' => isset($item['PriceGroupPrices'])
                ? json_encode($item['PriceGroupPrices'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,

            'payload_json' => $payloadJson,
        ];

        // FK-safe: null BrandID/VendorID if missing
        if ($main['BrandID'] !== null) {
            $stmtChkBrand->execute([':id' => $main['BrandID']]);
            if (!$stmtChkBrand->fetchColumn()) $main['BrandID'] = null;
        }
        if ($main['VendorID'] !== null) {
            $stmtChkVendor->execute([':id' => $main['VendorID']]);
            if (!$stmtChkVendor->fetchColumn()) $main['VendorID'] = null;
        }

        $stmtUpsertItem->execute($main);
        $totalUpserted++;

        // Replace children per SKU
        $stmtDelIsd->execute([':sku' => $sku]);
        foreach ($isdRows as $r) {
            $stmtInsIsd->execute([
                ':item_sku' => $sku,
                ':idx' => $r['Index'],
                ':name' => $r['Name'],
                ':val' => $r['Value'],
            ]);
            $totalIsds++;
        }

        $stmtDelImg->execute([':sku' => $sku]);
        foreach ($imageRows as $r) {
            $stmtInsImg->execute([
                ':item_sku' => $sku,
                ':idx' => $r['Index'],
                ':url' => $r['URL'],
                ':w' => $r['Width'],
                ':h' => $r['Height'],
                ':udt' => $r['UpdateDateTime'],
            ]);
            $totalImages++;
        }

        $inBatch++;
        if ($inBatch >= $batchSize) {
            $pdo->commit();
            $pdo->beginTransaction();
            $inBatch = 0;
            echo "Progress: processed " . ($i + 1) . " / {$total}\n";
        }
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    echo "\nSYNC SUCCESS\n";
    echo "Items upserted: {$totalUpserted}\n";
    echo "ISDs inserted: {$totalIsds}\n";
    echo "Images inserted: {$totalImages}\n";
    echo "Skipped (missing SKU): {$skippedNoSku}\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . "\n";
}
