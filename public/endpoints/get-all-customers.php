<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';

function soapToArray(mixed $value): mixed
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = soapToArray($v);
        }
        return $out;
    }

    if (is_object($value)) {
        $out = [];
        foreach (get_object_vars($value) as $k => $v) {
            $out[$k] = soapToArray($v);
        }
        return $out;
    }

    return $value;
}

function ensureList(mixed $maybeList): array
{
    if ($maybeList === null) return [];
    if (is_array($maybeList)) return $maybeList;
    return [$maybeList];
}

function extractCustomers(mixed $result): array
{
    $arr = soapToArray($result);

    // Common pattern: ['Customer' => ...]
    if (is_array($arr) && array_key_exists('Customer', $arr)) {
        return ensureList($arr['Customer']);
    }

    // Sometimes: ['Customers' => ['Customer' => ...]]
    if (is_array($arr) && isset($arr['Customers']['Customer'])) {
        return ensureList($arr['Customers']['Customer']);
    }

    // If it’s already a numeric list, use it
    if (is_array($arr)) {
        $keys = array_keys($arr);
        $isList = $keys === range(0, count($keys) - 1);
        if ($isList) return $arr;
    }

    return [];
}

try {
    $payload = [
        'AuthenticationInfo' => $authInfo,
    ];

    $response = $soapClient->GetAllCustomers($payload);
    $result   = $response->GetAllCustomersResult ?? null;

    echo "GetAllCustomers SUCCESS\n\n";

    if ($result === null) {
        echo "No customers returned (null result).\n\n";
        echo "Raw response shape:\n";
        print_r($response);
        exit;
    }

    $customers = extractCustomers($result);

    if (!$customers) {
        echo "Could not detect customer list. Raw result shape:\n";
        print_r($result);
        exit;
    }

    echo "Customer count (detected): " . count($customers) . "\n";

    // Optional: limit output for testing (default 0 = print all)
    $limit = (int)($_GET['limit'] ?? 0);
    if ($limit < 0) $limit = 0;

    if ($limit > 0) {
        $customers = array_slice($customers, 0, $limit);
        echo "Printing first {$limit} customers...\n\n";
    } else {
        echo "Printing ALL customers...\n\n";
    }

    foreach ($customers as $i => $cust) {
        echo "-----------------------------\n";
        echo "Customer #" . ($i + 1) . "\n";
        echo "-----------------------------\n";
        print_r($cust);
        echo "\n";
    }

} catch (SoapFault $fault) {
    http_response_code(502);

    echo "GetAllCustomers FAILED (SoapFault)\n";
    echo "Fault code: {$fault->faultcode}\n";
    echo "Fault string: {$fault->faultstring}\n\n";

    $debug = (bool)filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    if ($debug && isset($soapClient)) {
        echo "--- Last SOAP Request (debug only; may contain credentials) ---\n";
        echo $soapClient->__getLastRequest() . "\n\n";
        echo "--- Last SOAP Response ---\n";
        echo $soapClient->__getLastResponse() . "\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "GetAllCustomers FAILED\n";
    echo $e->getMessage() . "\n";
}
