<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

/**
 * Auto-generated endpoint tester for: CurrentServerDateTime
 *
 * - parameters (CurrentServerDateTime)
 *
 * Usage: ?parameters=...
 *
 * Notes:
 * - AuthenticationInfo is injected from your .env via Bootstrap.php
 * - Other parameters must be passed via query string.
 */

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

    // Call SOAP method
    $response = $soapClient->CurrentServerDateTime($payload);

    // Typical SOAP result property naming: <MethodName>Result
    $resultProp = 'CurrentServerDateTimeResult';
    $result = $response->{$resultProp} ?? $response;

    echo "CurrentServerDateTime SUCCESS\n\n";
    print_r($result);

} catch (SoapFault $fault) {
    http_response_code(502);

    echo "CurrentServerDateTime FAILED (SoapFault)\n";
    echo "Fault code: {$fault->faultcode}\n";
    echo "Fault string: {$fault->faultstring}\n";
}