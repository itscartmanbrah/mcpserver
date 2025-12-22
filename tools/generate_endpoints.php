<?php
declare(strict_types=1);

/**
 * Generates one PHP endpoint test page per SOAP operation in the WSDL,
 * and fixes the common .NET "wrapper parameter" pattern where operations appear as:
 *   SomeMethod(SomeMethodRequest $parameters)
 *
 * In that case, it unwraps the request type from __getTypes() so your endpoint page
 * asks for real query-string parameters instead of "?parameters=...".
 *
 * Run (Windows):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\mcpserver\tools\generate_endpoints.php
 */

$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    exit("Cannot resolve project root.\n");
}

$endpointsDir = $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'endpoints';
if (!is_dir($endpointsDir) && !mkdir($endpointsDir, 0775, true)) {
    exit("Failed to create endpoints dir: {$endpointsDir}\n");
}

$wsdl = 'http://eweb.retailedgeconsultants.com/eWebService.svc?singleWsdl';

// Client used for introspection
$client = new SoapClient($wsdl, [
    'soap_version' => SOAP_1_1,
    'exceptions'   => true,
    'trace'        => false,
    'cache_wsdl'   => WSDL_CACHE_BOTH,
]);

$functions = $client->__getFunctions();
$types     = $client->__getTypes();

sort($functions);

/**
 * Parse a SoapClient function signature string like:
 *  "ActiveItem GetActiveItemBySKU(AuthInfo $AuthenticationInfo, string $SKU)"
 * or wrapper form:
 *  "GetAllActiveItemsResponse GetAllActiveItems(GetAllActiveItems $parameters)"
 *
 * Returns:
 *  [
 *    'returnType' => 'ActiveItem',
 *    'method' => 'GetActiveItemBySKU',
 *    'params' => [
 *        ['type' => 'AuthInfo', 'name' => 'AuthenticationInfo'],
 *        ['type' => 'string', 'name' => 'SKU'],
 *    ]
 *  ]
 */
function parseSoapSignature(string $sig): array
{
    $sig = trim($sig);

    if (!preg_match('/^(\S+)\s+(\w+)\((.*)\)$/', $sig, $m)) {
        return ['returnType' => '', 'method' => '', 'params' => []];
    }

    $returnType = $m[1];
    $method     = $m[2];
    $paramsRaw  = trim($m[3]);

    $params = [];
    if ($paramsRaw !== '') {
        $parts = array_map('trim', explode(',', $paramsRaw));
        foreach ($parts as $p) {
            if (preg_match('/^(\S+)\s+\$(\w+)$/', $p, $pm)) {
                $params[] = ['type' => $pm[1], 'name' => $pm[2]];
            }
        }
    }

    return ['returnType' => $returnType, 'method' => $method, 'params' => $params];
}

/**
 * Build a map: TypeName => array of fields [['type'=>'AuthInfo','name'=>'AuthenticationInfo'], ...]
 *
 * __getTypes() commonly contains lines like:
 *  "struct GetActiveItemBySKU { AuthInfo AuthenticationInfo; string SKU; }"
 */
function buildTypeMap(array $types): array
{
    $map = [];

    foreach ($types as $t) {
        $t = trim($t);

        // Normalize whitespace to make regex more reliable
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;

        // Match: struct TypeName { ... }
        if (!preg_match('/^struct\s+(\w+)\s*\{\s*(.+)\s*\}\s*$/', $t, $m)) {
            continue;
        }

        $typeName = $m[1];
        $body     = trim($m[2]);

        $fields = [];

        // Fields are typically separated by ";"
        $parts = preg_split('/;\s*/', $body);
        if ($parts === false) {
            continue;
        }

        foreach ($parts as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Match: <Type> <Name>
            if (preg_match('/^(\S+)\s+(\w+)$/', $line, $fm)) {
                $fields[] = ['type' => $fm[1], 'name' => $fm[2]];
            }
        }

        if ($fields) {
            $map[$typeName] = $fields;
        }
    }

    return $map;
}

$typeMap = buildTypeMap($types);

function kebab(string $name): string
{
    $name = preg_replace('/([a-z])([A-Z])/', '$1-$2', $name) ?? $name;
    return strtolower($name);
}

function isAuthParam(array $p): bool
{
    $name = strtolower($p['name'] ?? '');
    $type = strtolower($p['type'] ?? '');
    return ($name === 'authenticationinfo' || strpos($type, 'authinfo') !== false);
}

/**
 * If the method signature is wrapper-form, unwrap it:
 *   Method(WrapperType $parameters) -> use fields of WrapperType from $typeMap.
 */
function unwrapParametersIfNeeded(array $parsed, array $typeMap): array
{
    if (count($parsed['params']) !== 1) {
        return $parsed;
    }

    $only = $parsed['params'][0];
    $paramName = strtolower($only['name'] ?? '');
    $wrapperType = $only['type'] ?? '';

    // Most common case: "$parameters"
    if ($paramName === 'parameters' && $wrapperType !== '' && isset($typeMap[$wrapperType])) {
        $parsed['params'] = $typeMap[$wrapperType];
        return $parsed;
    }

    return $parsed;
}

function buildEndpointPhp(string $method, array $params): string
{
    // Build docs + query requirements
    $paramDocs = [];
    $paramReads = [];

    $usagePairs = [];

    foreach ($params as $p) {
        $type = $p['type'];
        $name = $p['name'];

        $paramDocs[] = " * - {$name} ({$type})";

        // Inject AuthenticationInfo (never ask user to pass it)
        if (isAuthParam($p)) {
            $paramReads[] = "    // Inject authentication for this call\n    \$payload['AuthenticationInfo'] = \$authInfo;";
            continue;
        }

        $key = strtolower($name);
        $usagePairs[] = "{$key}=...";

        // Cast rules
        $castLine = "\$payload['{$name}'] = \$value;";
        $t = strtolower($type);

        if (in_array($t, ['int', 'integer', 'long', 'short'], true)) {
            $castLine = "\$payload['{$name}'] = (int)\$value;";
        } elseif (in_array($t, ['boolean', 'bool'], true)) {
            $castLine = "\$payload['{$name}'] = filter_var(\$value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);";
        }

        $paramReads[] =
            "    \$value = \$_GET['{$key}'] ?? null;\n" .
            "    if (\$value === null || \$value === '') {\n" .
            "        http_response_code(400);\n" .
            "        echo \"Missing required query parameter: {$key}\\n\";\n" .
            "        echo \"Usage: ?\" . implode('&', " . var_export($usagePairs, true) . ") . \"\\n\";\n" .
            "        exit;\n" .
            "    }\n" .
            "    {$castLine}";
    }

    // If there are no non-auth params, show a simpler usage message
    $usageLine = $usagePairs
        ? ("Usage: ?" . implode('&', $usagePairs))
        : "Usage: (no query parameters required)";

    $paramDocsBlock  = $paramDocs ? implode("\n", $paramDocs) : " * - (no parameters)";
    $paramReadsBlock = $paramReads ? implode("\n\n", $paramReads) : "    // No payload fields required\n    \$payload = ['AuthenticationInfo' => \$authInfo];";

    return <<<PHP
<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

/**
 * Auto-generated endpoint tester for: {$method}
 *
{$paramDocsBlock}
 *
 * {$usageLine}
 *
 * Notes:
 * - AuthenticationInfo is injected from your .env via Bootstrap.php
 * - Other parameters must be passed via query string.
 */

require __DIR__ . '/../../src/Bootstrap.php';

try {
    \$payload = [];

{$paramReadsBlock}

    // Call SOAP method
    \$response = \$soapClient->{$method}(\$payload);

    // Typical SOAP result property naming: <MethodName>Result
    \$resultProp = '{$method}Result';
    \$result = \$response->{\$resultProp} ?? \$response;

    echo "{$method} SUCCESS\\n\\n";
    print_r(\$result);

} catch (SoapFault \$fault) {
    http_response_code(502);

    echo "{$method} FAILED (SoapFault)\\n";
    echo "Fault code: {\$fault->faultcode}\\n";
    echo "Fault string: {\$fault->faultstring}\\n";
}
PHP;
}

$links = [];

// Generate each endpoint page
foreach ($functions as $sig) {
    $parsed = parseSoapSignature($sig);
    if ($parsed['method'] === '') {
        continue;
    }

    // Unwrap "$parameters" wrapper where possible
    $parsed = unwrapParametersIfNeeded($parsed, $typeMap);

    $method = $parsed['method'];
    $file   = kebab($method) . '.php';
    $path   = $endpointsDir . DIRECTORY_SEPARATOR . $file;

    $php = buildEndpointPhp($method, $parsed['params']);
    file_put_contents($path, $php);

    $links[] = [
        'method' => $method,
        'file'   => $file,
        'params' => $parsed['params'],
    ];
}

// Generate index page
$indexPath = $endpointsDir . DIRECTORY_SEPARATOR . 'index.php';

$indexPhp  = "<?php\nheader('Content-Type: text/html; charset=utf-8');\n?>\n";
$indexPhp .= "<h1>eWeb SOAP Endpoints (Generated)</h1>\n";
$indexPhp .= "<p>Click an endpoint. If it requires inputs, add them as query string parameters.</p>\n";
$indexPhp .= "<ul>\n";

foreach ($links as $l) {
    $qs = [];
    foreach ($l['params'] as $p) {
        if (isAuthParam($p)) {
            continue;
        }
        $qs[] = strtolower($p['name']) . '=';
    }
    $queryPreview = $qs ? ('?' . implode('&', $qs)) : '';
    $url = htmlspecialchars($l['file'] . $queryPreview);
    $label = htmlspecialchars($l['method']);

    $indexPhp .= "<li><a href=\"{$url}\">{$label}</a></li>\n";
}

$indexPhp .= "</ul>\n";

file_put_contents($indexPath, $indexPhp);

echo "Generated " . count($links) . " endpoint pages in: {$endpointsDir}\n";
echo "Open: http://localhost/mcpserver/public/endpoints/index.php\n";
