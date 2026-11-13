<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

use InvalidArgumentException;
use Ritmo\Application\ConnectionNotConfigured;
use Ritmo\Application\ConnectorSource;
use Ritmo\Connectors\Beds24\Beds24Config;
use Ritmo\Connectors\Beds24\Beds24Connector;
use Ritmo\Connectors\Beds24\Beds24Credentials;
use Ritmo\Connectors\PriceLabs\PriceLabsConfig;
use Ritmo\Connectors\PriceLabs\PriceLabsConnector;
use Ritmo\Connectors\Support\BaseUrl;
use Ritmo\Contracts\ConnectionStatus;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Contracts\Logger;
use Ritmo\Contracts\PmsConnector;
use Ritmo\Contracts\PricingConnector;
use Ritmo\Contracts\WriteAuthority;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Repository\QuotaLogRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Http\CreditAwareHttpClient;
use Ritmo\Infrastructure\Http\CreditHeaders;
use Ritmo\Infrastructure\Http\DbQuotaLedger;
use Ritmo\Infrastructure\Http\GuardedHttpClient;
use Ritmo\Infrastructure\Http\RateLimit;
use Ritmo\Infrastructure\Http\RateLimiter;
use Ritmo\Infrastructure\Http\ReadRouteAllowList;
use Ritmo\Infrastructure\Http\RequestTarget;
use Ritmo\Infrastructure\Http\ThrottledHttpClient;
use Ritmo\Infrastructure\Http\WriteRouteAllowList;
use Ritmo\Infrastructure\Log\LogSanitizer;
use Ritmo\Infrastructure\Secrets\SodiumVault;
use Ritmo\Infrastructure\Support\SystemSleeper;
use Throwable;

/**
 * Builds the Beds24 and PriceLabs connectors of the site from the secrets vault and the
 * connection settings, behind the read-only HTTP guard. Base URLs other than production come
 * from the constants RITMO_BEDS24_BASE_URL and RITMO_PRICELABS_BASE_URL, honoured only while
 * RITMO_DEV is true.
 */
final class ConnectorFactory implements ConnectorSource
{
    public const BEDS24_SECRET = 'beds24_token';

    public const PRICELABS_SECRET = 'pricelabs_api_key';

    /** Settings key: "refresh" (a refresh token traded for access tokens) or "long_life". */
    public const BEDS24_TOKEN_KIND = 'beds24_token_kind';

    /** Settings key: Beds24 property ids to read bookings of, comma separated; empty for all. */
    public const BEDS24_PROPERTY_IDS = 'beds24_property_ids';

    /** Settings key: the PMS name PriceLabs uses for the listings. */
    public const PRICELABS_PMS = 'pricelabs_pms';

    public const DEFAULT_PRICELABS_PMS = 'beds24';

    /**
     * Constant that switches off the pricing system's calendar read. The read is on by default
     * (project decision: POST /listing_prices reads, it changes nothing, and the engine needs
     * the floor and ceiling in force); define it as false in wp-config.php to read prices from
     * the PMS only.
     */
    public const CALENDAR_READ_CONSTANT = 'RITMO_PRICING_CALENDAR_READ';

    public const BEDS24_URL_CONSTANT = 'RITMO_BEDS24_BASE_URL';

    public const PRICELABS_URL_CONSTANT = 'RITMO_PRICELABS_BASE_URL';

    public function __construct(
        private readonly SodiumVault $vault,
        private readonly KeyValueStore $settings,
        private readonly RoomTypeRepository $roomTypes,
        private readonly QuotaLogRepository $quotaLog,
        private readonly HttpClient $wire,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly LogSanitizer $sanitizer,
        private readonly ?WriteAuthority $authority = null,
    ) {
    }

    public function pms(): PmsConnector
    {
        $token = $this->secret(self::BEDS24_SECRET, 'Beds24');
        $credentials = $this->settings->get(self::BEDS24_TOKEN_KIND, 'refresh') === 'long_life'
            ? Beds24Credentials::longLifeToken($token)
            : Beds24Credentials::refreshToken($token);
        $units = [];
        foreach ($this->roomTypes->all() as $room) {
            if ($room->pmsRef !== '') {
                $units[$room->pmsRef] = $room->units;
            }
        }
        $config = new Beds24Config($credentials, $this->roomTypes->pmsMap(), self::propertyIds((string) $this->settings->get(self::BEDS24_PROPERTY_IDS, '')), self::constantUrl(self::BEDS24_URL_CONSTANT), Beds24Config::DEFAULT_MINIMUM_CREDIT, $units);

        return new Beds24Connector(
            new CreditAwareHttpClient($this->guarded(), $this->clock, new SystemSleeper(), CreditHeaders::fiveMinuteWindow()),
            $config,
            $this->clock,
            $this->logger,
            $this->authority,
        );
    }

    public function pricing(): PricingConnector
    {
        $limiter = new RateLimiter($this->clock, new SystemSleeper(), [
            RateLimit::perMinute(PriceLabsConnector::REQUESTS_PER_MINUTE),
            RateLimit::perHour(PriceLabsConnector::REQUESTS_PER_HOUR),
        ]);

        return new PriceLabsConnector(
            new ThrottledHttpClient($this->guarded(), $limiter, PriceLabsConnector::ID),
            $this->pricingConfig(),
            $this->clock,
            new DbQuotaLedger($this->quotaLog),
            $this->logger,
            $this->authority,
        );
    }

    public function readsPricingCalendar(): bool
    {
        return !defined(self::CALENDAR_READ_CONSTANT) || constant(self::CALENDAR_READ_CONSTANT) !== false;
    }

    /**
     * Tries a connection; never throws.
     */
    public function test(string $provider): ConnectionStatus
    {
        try {
            return $provider === PriceLabsConnector::ID ? $this->pricing()->testConnection() : $this->pms()->testConnection();
        } catch (Throwable $failure) {
            return new ConnectionStatus(false, str_starts_with($failure::class, 'Ritmo\\') || $failure instanceof InvalidArgumentException ? $failure->getMessage() : 'The connection could not be built.');
        }
    }

    /**
     * @return list<string> ids of positive whole numbers
     */
    public static function propertyIds(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $text)), static fn (string $id): bool => preg_match('/^[1-9]\d{0,11}$/', $id) === 1));
    }

    /**
     * The PMS of the listings as the pricing system names it: the payload of a plan carries it.
     */
    public function pricingPmsName(): string
    {
        return (string) $this->settings->get(self::PRICELABS_PMS, self::DEFAULT_PRICELABS_PMS);
    }

    private function pricingConfig(): PriceLabsConfig
    {
        return new PriceLabsConfig(
            $this->secret(self::PRICELABS_SECRET, 'PriceLabs'),
            $this->pricingPmsName(),
            self::constantUrl(self::PRICELABS_URL_CONSTANT)
        );
    }

    /**
     * Only GET leaves the site, plus the one calendar read of the pricing system (exact host and
     * path) unless the site switched it off.
     */
    private function guarded(): GuardedHttpClient
    {
        $routes = ReadRouteAllowList::none();
        if ($this->readsPricingCalendar()) {
            $base = self::constantUrl(self::PRICELABS_URL_CONSTANT);
            $target = RequestTarget::fromUrl((new PriceLabsConfig('placeholder-key', self::DEFAULT_PRICELABS_PMS, $base))->baseUrl . PriceLabsConnector::PRICES_PATH);
            $routes = $routes->with(PriceLabsConnector::ID, 'POST', $target->host, $target->path);
        }

        return new GuardedHttpClient($this->wire, $routes, $this->logger, $this->writeRoutes(), $this->authority);
    }

    /**
     * The write routes of both systems at their base URLs (exact paths; a booking route cannot
     * be declared), only when the write gate is the authority: a write needs a live token of it.
     */
    private function writeRoutes(): ?WriteRouteAllowList
    {
        if ($this->authority === null) {
            return null;
        }
        $pricing = (new PriceLabsConfig('placeholder-key', self::DEFAULT_PRICELABS_PMS, self::constantUrl(self::PRICELABS_URL_CONSTANT)))->baseUrl;
        $pms = BaseUrl::resolve(Beds24Connector::BASE_URL, self::constantUrl(self::BEDS24_URL_CONSTANT));
        $routes = WriteRouteAllowList::none();
        foreach ([...PriceLabsConnector::WRITE_ROUTES, PriceLabsConnector::LISTING_ROUTE] as [$method, $path]) {
            $routes = $routes->withBase(PriceLabsConnector::ID, $method, $pricing, $path);
        }

        return $routes->withBase(Beds24Connector::ID, 'POST', $pms, Beds24Connector::CALENDAR_PATH);
    }

    private function secret(string $name, string $label): string
    {
        if (!$this->vault->has($name)) {
            throw ConnectionNotConfigured::forProvider($label);
        }
        $secret = $this->vault->reveal($name);
        $this->sanitizer->registerSecret($secret);

        return $secret;
    }

    private static function constantUrl(string $constant): ?string
    {
        return defined($constant) ? (string) constant($constant) : null;
    }
}
