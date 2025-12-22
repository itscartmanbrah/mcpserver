<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';

// Local-only: bump memory if you must (comment out if you prefer php.ini)
@ini_set('memory_limit', '1024M');

$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 3;

try {
    $response = $soapClient->GetAllActiveItems([
        'AuthenticationInfo' => $authInfo,
    ]);

    $result = $response->GetAllActiveItemsResult ?? null;

    echo "GetAllActiveItems SUCCESS\n\n";

    if ($result === null) {
        echo "Result was null.\n";
        exit;
    }

    // SOAP can return array or object depending on mapping
    if (is_array($result)) {
        $count = count($result);
        echo "Count: {$count}\n";
        echo "Showing first {$limit} item(s):\n\n";

        $sample = array_slice($result, 0, $limit);
        print_r($sample);
        exit;
    }

    // Fallback: show shape only
    echo "Result type: " . gettype($result) . "\n\n";
    print_r($result);

} catch (SoapFault $fault) {
    http_response_code(502);
    echo "GetAllActiveItems FAILED (SoapFault)\n";
    echo "Fault code: {$fault->faultcode}\n";
    echo "Fault string: {$fault->faultstring}\n";
}
