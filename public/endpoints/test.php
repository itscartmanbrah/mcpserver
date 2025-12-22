<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

/**
 * Auto-generated endpoint tester for: Test
 *
 * - EchoString (string)
 *
 * Usage: ?echostring=...
 *
 * Notes:
 * - AuthenticationInfo is injected from your .env via Bootstrap.php
 * - Other parameters must be passed via query string.
 */

require __DIR__ . '/../../src/Bootstrap.php';

try {
    $payload = [];

    $value = $_GET['echostring'] ?? null;
    if ($value === null || $value === '') {
        http_response_code(400);
        echo "Missing required query parameter: echostring\n";
        echo "Usage: ?" . implode('&', array (
  0 => 'echostring=...',
)) . "\n";
        exit;
    }
    $payload['EchoString'] = $value;

    // Call SOAP method
    $response = $soapClient->Test($payload);

    // Typical SOAP result property naming: <MethodName>Result
    $resultProp = 'TestResult';
    $result = $response->{$resultProp} ?? $response;

    echo "Test SUCCESS\n\n";
    print_r($result);

} catch (SoapFault $fault) {
    http_response_code(502);

    echo "Test FAILED (SoapFault)\n";
    echo "Fault code: {$fault->faultcode}\n";
    echo "Fault string: {$fault->faultstring}\n";
}