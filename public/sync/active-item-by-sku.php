<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';

use App\Support\Db;

// -----------------------
// Basic sync access gate
// -----------------------
// Accept either ?SYNC_TOKEN=... (preferred) or legacy ?token=...
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

/**
 * Normalize "empty-ish" values to NULL for FK columns.
 */
function nullIfBlank(mixed $v): ?string
{
    if ($v === null) return null;
    $s = trim((string)$v);
    return $s === '' ? null : $s;
}

// -----------------------
// Input
// -----------------------
$sku = trim((string)($_GET['sku'] ?? ''));
if ($sku === '') {
    http_response_code(400);
    echo "Missing required query parameter: sku\n";
    echo "Usage: ?sku=001-002-00385&SYNC_TOKEN=YOUR_TOKEN\n";
    exit;
}

try {
    // 1) Fetch from eWeb
    $response = $soapClient->GetActiveItemBySKU([
        'AuthenticationInfo' => $authInfo,
        'SKU' => $sku,
    ]);

    $itemObj = $response->GetActiveItemBySKUResult ?? null;
    if ($itemObj === null) {
        echo "No item returned for SKU {$sku}. It may be inactive or not found.\n";
        exit;
    }

    // 2) Normalize
    $item = soapToArray($itemObj);

    // Child lists
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

    // Main row (match your column names)
    $payloadJson = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $main = [
        'SKU' => (string)($item['SKU'] ?? $sku),

        'StockNum'   => isset($item['StockNum']) ? (int)$item['StockNum'] : null,
        'OldKey'     => $item['OldKey'] ?? null,
        'OldBarcode' => $item['OldBarcode'] ?? null,
        'Barcode'    => $item['Barcode'] ?? null,

        // normalize FK inputs early
        'BrandID'    => nullIfBlank($item['BrandID'] ?? null),
        'CategoryID' => isset($item['CategoryID']) ? (int)$item['CategoryID'] : null,
        'VendorID'   => nullIfBlank($item['VendorID'] ?? null),

        'DesignNum'  => $item['DesignNum'] ?? null,
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
        'SpecialPrice' => isset($item['SpecialPrice']) ? (string)$item['SpecialPrice'] : null,
        'CataloguePrice' => isset($item['CataloguePrice']) ? (string)$item['CataloguePrice'] : null,

        // Sentinel-friendly (VARCHAR columns)
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

        'Location' => $item['Location'] ?? null,

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

    // 3) Persist
    $pdo = Db::pdo();
    $pdo->beginTransaction();

    // ---------------------------------------------------------
    // FK-safe: if BrandID/VendorID do not exist, set them NULL.
    // This prevents SQLSTATE[23000] 1452 when FK constraints exist.
    // ---------------------------------------------------------
    if ($main['BrandID'] !== null) {
        $chk = $pdo->prepare("SELECT 1 FROM eweb_brands WHERE BrandID = :id LIMIT 1");
        $chk->execute([':id' => $main['BrandID']]);
        if (!$chk->fetchColumn()) {
            $main['BrandID'] = null;
        }
    }

    if ($main['VendorID'] !== null) {
        $chk = $pdo->prepare("SELECT 1 FROM eweb_vendors WHERE VendorID = :id LIMIT 1");
        $chk->execute([':id' => $main['VendorID']]);
        if (!$chk->fetchColumn()) {
            $main['VendorID'] = null;
        }
    }

    $sqlItem = "
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
            updated_at=NOW()
    ";

    $pdo->prepare($sqlItem)->execute($main);

    // Replace ISDs
    $pdo->prepare("DELETE FROM eweb_active_item_isds WHERE item_sku = :sku")
        ->execute([':sku' => $main['SKU']]);

    if ($isdRows) {
        $stmtIsd = $pdo->prepare("
            INSERT INTO eweb_active_item_isds (item_sku, `Index`, `Name`, `Value`)
            VALUES (:item_sku, :idx, :name, :val)
        ");
        foreach ($isdRows as $r) {
            $stmtIsd->execute([
                ':item_sku' => $main['SKU'],
                ':idx'  => $r['Index'],
                ':name' => $r['Name'],
                ':val'  => $r['Value'],
            ]);
        }
    }

    // Replace Images
    $pdo->prepare("DELETE FROM eweb_active_item_images WHERE item_sku = :sku")
        ->execute([':sku' => $main['SKU']]);

    if ($imageRows) {
        $stmtImg = $pdo->prepare("
            INSERT INTO eweb_active_item_images (item_sku, `Index`, URL, Width, Height, UpdateDateTime)
            VALUES (:item_sku, :idx, :url, :w, :h, :udt)
        ");
        foreach ($imageRows as $r) {
            $stmtImg->execute([
                ':item_sku' => $main['SKU'],
                ':idx' => $r['Index'],
                ':url' => $r['URL'],
                ':w'   => $r['Width'],
                ':h'   => $r['Height'],
                ':udt' => $r['UpdateDateTime'],
            ]);
        }
    }

    $pdo->commit();

    echo "SYNC SUCCESS\n";
    echo "SKU: {$main['SKU']}\n";
    echo "BrandID used: " . ($main['BrandID'] ?? 'NULL') . "\n";
    echo "VendorID used: " . ($main['VendorID'] ?? 'NULL') . "\n";
    echo "ISDs stored: " . count($isdRows) . "\n";
    echo "Images stored: " . count($imageRows) . "\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . "\n";
}
