<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

/**
 * Auto-generated endpoint tester for: GetAllCustomers
 *
 * - AuthenticationInfo (AuthInfo)
 *
 * Usage: (no query parameters required)
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

    // Call SOAP method
    $response = $soapClient->GetAllCustomers($payload);

    // Typical SOAP result property naming: <MethodName>Result
    $resultProp = 'GetAllCustomersResult';
    $result = $response->{$resultProp} ?? $response;

    echo "GetAllCustomers SUCCESS\n\n";
    print_r($result);

} catch (SoapFault $fault) {
    http_response_code(502);

    echo "GetAllCustomers FAILED (SoapFault)\n";
    echo "Fault code: {$fault->faultcode}\n";
    echo "Fault string: {$fault->faultstring}\n";
}