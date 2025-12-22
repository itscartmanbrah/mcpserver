<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');



require __DIR__ . '/../../src/Bootstrap.php';

try {
    $payload = [];

    $value = $_GET['parameters'] ?? null;
    if ($value === null || $value === '') {
        http_response_code(400);
        echo "Missing required query parameter: parameters\n";
        echo "Usage: ?" . implode('&', array (
  0 => 'parameters=...',
)) . "\n";
        exit;
    }
    $payload['parameters'] = $value;

    
    $response = $soapClient->CheckService($payload);

    
    $resultProp = 'CheckServiceResult';
    $result = $response->{$resultProp} ?? $response;

    echo "CheckService SUCCESS\n\n";
    print_r($result);

} catch (SoapFault $fault) {
    http_response_code(502);

    echo "CheckService FAILED (SoapFault)\n";
    echo "Fault code: {$fault->faultcode}\n";
    echo "Fault string: {$fault->faultstring}\n";
}