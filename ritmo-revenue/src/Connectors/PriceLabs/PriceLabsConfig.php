<?php

declare(strict_types=1);

namespace Ritmo\Connectors\PriceLabs;

use InvalidArgumentException;
use LogicException;
use Ritmo\Connectors\Support\BaseUrl;

/**
 * What the PriceLabs connector needs for one account: the API key and the name PriceLabs gives
 * to the PMS the listings come from.
 *
 * The key never appears in a dump of the object.
 */
final class PriceLabsConfig
{
    public readonly string $baseUrl;

    public readonly string $pmsName;

    /**
     * @param string      $apiKey  Customer API key of the account
     * @param string      $pmsName PMS of the listings as PriceLabs names it ("beds24"); sent with every listing read
     * @param string|null $baseUrl replacement for the API base URL; accepted only while RITMO_DEV is true
     *
     * @throws InvalidArgumentException on an empty or malformed key or PMS name, or a base URL that may not be used
     */
    public function __construct(
        private readonly string $apiKey,
        string $pmsName,
        ?string $baseUrl = null,
    ) {
        if ($apiKey === '' || preg_match('/[\x00-\x20\x7f]/', $apiKey) === 1) {
            throw new InvalidArgumentException('A PriceLabs API key cannot be empty or contain spaces or control characters.');
        }

        $pmsName = strtolower(trim($pmsName));
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,39}$/', $pmsName) !== 1) {
            throw new InvalidArgumentException('The PMS name is the short name PriceLabs uses for the PMS, such as "beds24".');
        }

        $this->pmsName = $pmsName;
        $this->baseUrl = BaseUrl::resolve(PriceLabsConnector::BASE_URL, $baseUrl);
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * @return array{api_key: string, pms_name: string, base_url: string}
     */
    public function __debugInfo(): array
    {
        return ['api_key' => '(hidden)', 'pms_name' => $this->pmsName, 'base_url' => $this->baseUrl];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LogicException always: a credential is never written to a cache, a session or a log
     */
    public function __serialize(): array
    {
        throw new LogicException('PriceLabs credentials cannot be serialised.');
    }
}
