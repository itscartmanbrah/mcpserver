<?php
declare(strict_types=1);

// Simple autoloader (no Composer)
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_readable($path)) {
        require $path;
    }
});

use App\Support\Env;

// Load .env (project root)
Env::load(dirname(__DIR__) . '/.env');

// WSDL
$wsdl = 'http://eweb.retailedgeconsultants.com/eWebService.svc?singleWsdl';

// AuthInfo array used by most calls
$authInfo = [
    'ClientNum'    => (int) Env::require('EWEB_CLIENT_NUM'),
    'Password'     => Env::require('EWEB_PASSWORD'),
    'SecurityCode' => Env::require('EWEB_SECURITY_CODE'),
];

// SOAP client
$soapClient = new SoapClient($wsdl, [
    'soap_version'        => SOAP_1_1,
    'exceptions'          => true,
    'trace'               => true,
    'cache_wsdl'          => WSDL_CACHE_BOTH,
    'connection_timeout'  => 20,
    'features'            => SOAP_SINGLE_ELEMENT_ARRAYS,
]);
$debug = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);