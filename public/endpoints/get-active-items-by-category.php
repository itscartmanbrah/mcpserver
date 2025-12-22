<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

/**
 * Auto-generated endpoint tester for: GetActiveItemsByCategory
 *
 * - AuthenticationInfo (AuthInfo)
 * - CategoryID (int)
 *
 * Usage: ?categoryid=...
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

    $value = $_GET['categoryid'] ?? null;
    if ($value === null || $value === '') {
        http_response_code(400);
        echo "Missing required query parameter: categoryid\n";
        echo "Usage: ?" . implode('&', array (
  0 => 'categoryid=...',
)) . "\n";
        exit;
    }
    $payload['CategoryID'] = (int)$value;

    // Call SOAP method
    $response = $soapClient->GetActiveItemsByCategory($payload);

    // Typical SOAP result property naming: <MethodName>Result
    $resultProp = 'GetActiveItemsByCategoryResult';
    $result = $response->{$resultProp} ?? $response;

    echo "GetActiveItemsByCategory SUCCESS\n\n";
    print_r($result);

} catch (SoapFault $fault) {
    http_response_code(502);

    echo "GetActiveItemsByCategory FAILED (SoapFault)\n";
    echo "Fault code: {$fault->faultcode}\n";
    echo "Fault string: {$fault->faultstring}\n";
}