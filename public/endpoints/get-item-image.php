<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

/**
 * Auto-generated endpoint tester for: GetItemImage
 *
 * - AuthenticationInfo (AuthInfo)
 * - CategoryID (int)
 * - StockNum (int)
 * - ImageIndex (int)
 *
 * Usage: ?categoryid=...&stocknum=...&imageindex=...
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

    $value = $_GET['stocknum'] ?? null;
    if ($value === null || $value === '') {
        http_response_code(400);
        echo "Missing required query parameter: stocknum\n";
        echo "Usage: ?" . implode('&', array (
  0 => 'categoryid=...',
  1 => 'stocknum=...',
)) . "\n";
        exit;
    }
    $payload['StockNum'] = (int)$value;

    $value = $_GET['imageindex'] ?? null;
    if ($value === null || $value === '') {
        http_response_code(400);
        echo "Missing required query parameter: imageindex\n";
        echo "Usage: ?" . implode('&', array (
  0 => 'categoryid=...',
  1 => 'stocknum=...',
  2 => 'imageindex=...',
)) . "\n";
        exit;
    }
    $payload['ImageIndex'] = (int)$value;

    // Call SOAP method
    $response = $soapClient->GetItemImage($payload);

    // Typical SOAP result property naming: <MethodName>Result
    $resultProp = 'GetItemImageResult';
    $result = $response->{$resultProp} ?? $response;

    echo "GetItemImage SUCCESS\n\n";
    print_r($result);

} catch (SoapFault $fault) {
    http_response_code(502);

    echo "GetItemImage FAILED (SoapFault)\n";
    echo "Fault code: {$fault->faultcode}\n";
    echo "Fault string: {$fault->faultstring}\n";
}