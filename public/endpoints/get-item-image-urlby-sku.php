<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

/**
 * Auto-generated endpoint tester for: GetItemImageURLBySKU
 *
 * - AuthenticationInfo (AuthInfo)
 * - SKU (string)
 * - ImageIndex (int)
 *
 * Usage: ?sku=...&imageindex=...
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

    $value = $_GET['sku'] ?? null;
    if ($value === null || $value === '') {
        http_response_code(400);
        echo "Missing required query parameter: sku\n";
        echo "Usage: ?" . implode('&', array (
  0 => 'sku=...',
)) . "\n";
        exit;
    }
    $payload['SKU'] = $value;

    $value = $_GET['imageindex'] ?? null;
    if ($value === null || $value === '') {
        http_response_code(400);
        echo "Missing required query parameter: imageindex\n";
        echo "Usage: ?" . implode('&', array (
  0 => 'sku=...',
  1 => 'imageindex=...',
)) . "\n";
        exit;
    }
    $payload['ImageIndex'] = (int)$value;

    // Call SOAP method
    $response = $soapClient->GetItemImageURLBySKU($payload);

    // Typical SOAP result property naming: <MethodName>Result
    $resultProp = 'GetItemImageURLBySKUResult';
    $result = $response->{$resultProp} ?? $response;

    echo "GetItemImageURLBySKU SUCCESS\n\n";
    print_r($result);

} catch (SoapFault $fault) {
    http_response_code(502);

    echo "GetItemImageURLBySKU FAILED (SoapFault)\n";
    echo "Fault code: {$fault->faultcode}\n";
    echo "Fault string: {$fault->faultstring}\n";
}