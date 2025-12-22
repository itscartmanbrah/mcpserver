<?php
declare(strict_types=1);

namespace App\Eweb;

final class EwebClientFactory
{
    public static function make(string $wsdl): \SoapClient
    {
        $options = [
            'soap_version'        => SOAP_1_1,
            'exceptions'          => true,
            'trace'               => true,
            'cache_wsdl'          => WSDL_CACHE_BOTH,
            'connection_timeout'  => 20,
            'features'            => SOAP_SINGLE_ELEMENT_ARRAYS,
        ];

        return new \SoapClient($wsdl, $options);
    }
}
