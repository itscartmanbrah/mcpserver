<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';

$sku = $_GET['sku'] ?? '001-002-01077';

try {
    $item = $eweb->getActiveItemBySku($sku);

    echo "GetActiveItemBySKU SUCCESS\n";
    echo "SKU: {$sku}\n\n";

    if ($item === null) {
        echo "No item returned (null). SKU may be inactive or not found.\n";
        exit;
    }

    print_r($item);

} catch (SoapFault $fault) {
    http_response_code(502);

    echo "GetActiveItemBySKU FAILED (SoapFault)\n";
    echo "Fault code: {$fault->faultcode}\n";
    echo "Fault string: {$fault->faultstring}\n\n";

    // Local debugging only; contains sensitive data
    echo "--- Last SOAP Request (local debug only) ---\n";
    echo $eweb->lastRequest() . "\n\n";
    echo "--- Last SOAP Response ---\n";
    echo $eweb->lastResponse() . "\n";
}
