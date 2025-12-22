<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

/**
 * Auto-generated endpoint tester for: GetGroupCustomersByStatus
 *
 * - AuthenticationInfo (AuthInfo)
 * - Active (boolean)
 *
 * Usage: ?active=...
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

    $value = $_GET['active'] ?? null;
    if ($value === null || $value === '') {
        http_response_code(400);
        echo "Missing required query parameter: active\n";
        echo "Usage: ?" . implode('&', array (
  0 => 'active=...',
)) . "\n";
        exit;
    }
    $payload['Active'] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    // Call SOAP method
    $response = $soapClient->GetGroupCustomersByStatus($payload);

    // Typical SOAP result property naming: <MethodName>Result
    $resultProp = 'GetGroupCustomersByStatusResult';
    $result = $response->{$resultProp} ?? $response;

    echo "GetGroupCustomersByStatus SUCCESS\n\n";
    print_r($result);

} catch (SoapFault $fault) {
    http_response_code(502);

    echo "GetGroupCustomersByStatus FAILED (SoapFault)\n";
    echo "Fault code: {$fault->faultcode}\n";
    echo "Fault string: {$fault->faultstring}\n";
}