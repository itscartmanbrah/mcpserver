<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

/**
 * Auto-generated endpoint tester for: GetActiveItemsPricedByGroup
 *
 * - AuthenticationInfo (AuthInfo)
 * - SearchBy (ArrayOfSearchBaseParam)
 * - PriceParams (PriceGroupParam)
 *
 * Usage: ?searchby=...&priceparams=...
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

    $value = $_GET['searchby'] ?? null;
    if ($value === null || $value === '') {
        http_response_code(400);
        echo "Missing required query parameter: searchby\n";
        echo "Usage: ?" . implode('&', array (
  0 => 'searchby=...',
)) . "\n";
        exit;
    }
    $payload['SearchBy'] = $value;

    $value = $_GET['priceparams'] ?? null;
    if ($value === null || $value === '') {
        http_response_code(400);
        echo "Missing required query parameter: priceparams\n";
        echo "Usage: ?" . implode('&', array (
  0 => 'searchby=...',
  1 => 'priceparams=...',
)) . "\n";
        exit;
    }
    $payload['PriceParams'] = $value;

    // Call SOAP method
    $response = $soapClient->GetActiveItemsPricedByGroup($payload);

    // Typical SOAP result property naming: <MethodName>Result
    $resultProp = 'GetActiveItemsPricedByGroupResult';
    $result = $response->{$resultProp} ?? $response;

    echo "GetActiveItemsPricedByGroup SUCCESS\n\n";
    print_r($result);

} catch (SoapFault $fault) {
    http_response_code(502);

    echo "GetActiveItemsPricedByGroup FAILED (SoapFault)\n";
    echo "Fault code: {$fault->faultcode}\n";
    echo "Fault string: {$fault->faultstring}\n";
}