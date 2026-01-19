<?php
declare(strict_types=1);

namespace App\Eweb;

use SoapClient;

final class EwebClientFactory
{
    public static function make(string $wsdl): SoapClient
    {
        // Critical: stream_context timeout controls how long PHP waits for response headers/body.
        // Without this, large SOAP responses often fail with "Error Fetching http headers".
        $context = stream_context_create([
            'http' => [
                'timeout' => 300, // seconds
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        return new SoapClient($wsdl, [
            'soap_version' => SOAP_1_1,
            'exceptions' => true,
            'trace' => true,
            'cache_wsdl' => WSDL_CACHE_BOTH,

            // TCP connect timeout (not the same as overall read timeout)
            'connection_timeout' => 60,

            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,

            // Overall HTTP read timeout
            'stream_context' => $context,
        ]);
    }
}
