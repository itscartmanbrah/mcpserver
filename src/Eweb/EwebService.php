<?php
declare(strict_types=1);

namespace App\Eweb;

final class EwebService
{
    /** @var \SoapClient */
    private $client;

    /** @var array<string, mixed> */
    private $authInfo;

    /**
     * @param array<string, mixed> $authInfo
     */
    public function __construct(\SoapClient $client, array $authInfo)
    {
        $this->client = $client;
        $this->authInfo = $authInfo;
    }

    public function test(string $echoString): string
    {
        $res = $this->client->Test(['EchoString' => $echoString]);
        return (string)($res->TestResult ?? '');
    }

    /**
     * Wraps GetActiveItemBySKU(AuthenticationInfo, SKU)
     * Returns the ActiveItem object/array as mapped by SoapClient.
     */
    public function getActiveItemBySku(string $sku)
    {
        $res = $this->client->GetActiveItemBySKU([
            'AuthenticationInfo' => $this->authInfo,
            'SKU' => $sku,
        ]);

        return $res->GetActiveItemBySKUResult ?? null;
    }

    /**
     * Wraps GetAllBrands(AuthenticationInfo)
     * Returns ArrayOfBrand (often maps to an array of Brand objects).
     */
    public function getAllBrands()
    {
        $res = $this->client->GetAllBrands([
            'AuthenticationInfo' => $this->authInfo,
        ]);

        return $res->GetAllBrandsResult ?? null;
    }

    /**
     * Debug helpers (note: request may contain credentials).
     */
    public function lastRequest(): string
    {
        return (string)$this->client->__getLastRequest();
    }

    public function lastResponse(): string
    {
        return (string)$this->client->__getLastResponse();
    }
}
