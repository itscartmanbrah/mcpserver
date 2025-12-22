<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';

try {
    $brands = $eweb->getAllBrands();

    echo "GetAllBrands SUCCESS\n\n";

    if ($brands === null) {
        echo "No brands returned (null).\n";
        exit;
    }

    // Depending on SOAP mapping, this may be:
    // - an array of Brand objects
    // - a single Brand object
    // - an object containing an internal array
    if (is_array($brands)) {
        echo "Brand count: " . count($brands) . "\n\n";
        if (count($brands) > 0) {
            echo "First brand sample:\n";
            print_r($brands[0]);
        }
    } else {
        echo "Brands response shape:\n";
        print_r($brands);
    }

} catch (SoapFault $fault) {
    http_response_code(502);

    echo "GetAllBrands FAILED (SoapFault)\n";
    echo "Fault code: {$fault->faultcode}\n";
    echo "Fault string: {$fault->faultstring}\n\n";

    // Local debugging only; request may contain credentials
    echo "--- Last SOAP Request (local debug only) ---\n";
    echo $eweb->lastRequest() . "\n\n";
    echo "--- Last SOAP Response ---\n";
    echo $eweb->lastResponse() . "\n";
}
