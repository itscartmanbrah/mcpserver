<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

/**
 * Auto-generated endpoint tester for: GetCustomerByID
 *
 * - AuthenticationInfo (AuthInfo)
 * - CustomerID (string)
 *
 * Usage: ?customerid=...
 *
 * Notes:
 * - AuthenticationInfo is injected from your .env via Bootstrap.php
 * - Other parameters must be passed via query string.
 */

require __DIR__ . '/../../src/Bootstrap.php';

try {
    $payload = [];

    // Inject authentication for this call
    $payload['AuthenticationInfo'] = $authInfo;

    $value = $_GET['customerid'] ?? null;
    if ($value === null || $value === '') {
        http_response_code(400);
        echo "Missing required query parameter: customerid\n";
        echo "Usage: ?" . implode('&', array (
  0 => 'customerid=...',
)) . "\n";
        exit;
    }
    $payload['CustomerID'] = $value;

    // Call SOAP method
    $response = $soapClient->GetCustomerByID($payload);

    // Typical SOAP result property naming: <MethodName>Result
    $resultProp = 'GetCustomerByIDResult';
    $result = $response->{$resultProp} ?? $response;

    echo "GetCustomerByID SUCCESS\n\n";
    print_r($result);

} catch (SoapFault $fault) {
    http_response_code(502);

    echo "GetCustomerByID FAILED (SoapFault)\n";
    echo "Fault code: {$fault->faultcode}\n";
    echo "Fault string: {$fault->faultstring}\n";
}