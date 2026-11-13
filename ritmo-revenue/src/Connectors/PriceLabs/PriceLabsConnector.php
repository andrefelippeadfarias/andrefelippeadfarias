<?php

declare(strict_types=1);

namespace Ritmo\Connectors\PriceLabs;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Connectors\Support\HttpFailure;
use Ritmo\Connectors\Support\Payload;
use Ritmo\Connectors\Support\RequestBudget;
use Ritmo\Contracts\ConnectionStatus;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\Logger;
use Ritmo\Contracts\PricingConnector;
use Ritmo\Contracts\QuotaLedger;
use Ritmo\Contracts\WriteAuthority;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Contracts\WriteRequest;
use Ritmo\Contracts\WriteToken;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\ListingInfo;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;

/**
 * PriceLabs (Customer API) as the pricing system: the listing directory, the price calendar
 * and the date-specific overrides; and, for a write run, the date rules, their deletion, a
 * recalculation and a listing change.
 *
 * The calendar read is a POST by the API's design (PRICES_PATH); it changes nothing in the
 * account. Every write builds its body from a whitelist (OverrideWriteBody, ListingUpdateBody),
 * then asks the write authority (the write gate's live registry) whether the token allows exactly
 * that request, and only then sends it once through the HTTP client's write door. A connector
 * built without a write authority is read-only: every write method throws WriteBlocked.
 *
 * Limits of the API: REQUESTS_PER_MINUTE and REQUESTS_PER_HOUR. The hourly limit is enforced
 * here through the quota ledger, so it holds across runs. The per-minute limit is paced by the
 * HTTP client the connector is given (a rate limiter built from these constants); a 429 from
 * the API becomes RateLimited either way.
 */
final class PriceLabsConnector implements PricingConnector
{
    public const ID = 'pricelabs';

    public const BASE_URL = 'https://api.pricelabs.co/v1';

    /** The one read the API serves by POST, relative to the base URL. */
    public const PRICES_PATH = '/listing_prices';

    public const REQUESTS_PER_MINUTE = 60;

    public const REQUESTS_PER_HOUR = 1000;

    /** Write routes, relative to the base URL, for WriteRouteAllowList ("{id}" is the listing id). */
    public const WRITE_ROUTES = [['POST', '/listings/{id}/overrides'], ['DELETE', '/listings/{id}/overrides'], ['POST', self::RECALCULATION_PATH]];

    /** The listing change route: on the allow-list only where listing changes are enabled. */
    public const LISTING_ROUTE = ['POST', '/listings'];

    public const RECALCULATION_PATH = '/refresh_listing';

    private const LABEL = 'PriceLabs';

    private readonly PriceLabsClient $client;

    private readonly ListingMapper $listings;

    private readonly PriceNightMapper $prices;

    private readonly OverrideMapper $overrides;

    public function __construct(
        HttpClient $http,
        private readonly PriceLabsConfig $config,
        Clock $clock,
        QuotaLedger $ledger,
        private readonly Logger $logger,
        private readonly ?WriteAuthority $authority = null,
    ) {
        $budget = new RequestBudget($ledger, $clock, self::ID, self::LABEL, self::REQUESTS_PER_HOUR, 3600);
        $this->client = new PriceLabsClient($http, $config, $budget, $logger);
        $this->listings = new ListingMapper();
        $this->prices = new PriceNightMapper();
        $this->overrides = new OverrideMapper();
    }

    public function id(): string
    {
        return self::ID;
    }

    public function testConnection(): ConnectionStatus
    {
        try {
            $all = $this->readDirectory();
        } catch (ConnectorException $e) {
            return new ConnectionStatus(false, $e->getMessage(), array_filter(['http_status' => $e->httpStatus()], 'is_int'));
        }

        $mine = count(array_filter($all, $this->belongsToPms(...)));

        return new ConnectionStatus(
            true,
            sprintf('Connected to PriceLabs: %d of %d listings belong to the PMS "%s".', $mine, count($all), $this->config->pmsName),
            ['listings' => $mine, 'account_listings' => count($all), 'pms' => $this->config->pmsName]
        );
    }

    /**
     * The listings of the configured PMS (an account may hold listings of several).
     *
     * @return list<ListingInfo>
     */
    public function listListings(): array
    {
        $mine = array_filter($this->readDirectory(), $this->belongsToPms(...));

        return array_values(array_filter(array_map($this->listings->map(...), $mine)));
    }

    /**
     * hasDateOverride is always false here: the calendar of the API does not say whether a
     * night has a date-specific override. readOverrides() does.
     *
     * @return list<PriceNight>
     */
    public function readPrices(string $listingRef, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $range = new DateRange($from, $to);
        $listingRef = self::listingRef($listingRef);
        $operation = 'POST ' . self::PRICES_PATH;

        $answer = $this->client->readCalendar(['listings' => [[
            'id' => $listingRef,
            'pms' => $this->config->pmsName,
            'dateFrom' => Days::key($range->first()),
            'dateTo' => Days::key($range->last()),
        ]]]);

        $calendar = null;
        foreach (array_filter($answer, 'is_array') as $candidate) {
            if (Payload::text($candidate, 'id', 128) === $listingRef) {
                $calendar = $candidate;
                break;
            }
        }
        if ($calendar === null) {
            throw HttpFailure::unexpectedShape(self::ID, self::LABEL, $operation, 'the listing is not in the answer');
        }

        $problem = Payload::text($calendar, 'error_status', 64);
        if ($problem !== '') {
            $code = HttpFailure::safeCode($problem);

            throw new ConnectorException(
                sprintf('PriceLabs has no calendar for the listing%s.', $code === null ? '' : ' (' . $code . ')'),
                self::ID
            );
        }
        if (!is_array($calendar['data'] ?? null)) {
            throw HttpFailure::unexpectedShape(self::ID, self::LABEL, $operation, 'the listing has no list of nights');
        }

        return $this->inRange(Payload::rows($calendar, 'data'), $this->prices->map(...), $range, $operation);
    }

    /**
     * @return list<DateOverride>
     */
    public function readOverrides(string $listingRef, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $range = new DateRange($from, $to);
        $path = '/listings/' . rawurlencode(self::listingRef($listingRef)) . '/overrides';

        $answer = $this->client->get($path, [
            'pms' => $this->config->pmsName,
            'start_date' => Days::key($range->first()),
            'end_date' => Days::key($range->last()),
        ]);
        if (!is_array($answer['overrides'] ?? null)) {
            throw HttpFailure::unexpectedShape(self::ID, self::LABEL, 'GET ' . $path, 'there is no list of overrides');
        }

        return $this->inRange(Payload::rows($answer, 'overrides'), $this->overrides->map(...), $range, 'GET ' . $path);
    }

    /**
     * The recalculation is metered (three per listing per rolling day): the caller checks and
     * records the quota around this call.
     */
    public function requestRecalculation(WriteToken $token, string $listingRef): void
    {
        $listingRef = self::listingRef($listingRef);
        $this->authorise($token, new WriteRequest(WriteOperation::Recalculation, [$listingRef => []]));
        $this->client->write($token, 'POST', self::RECALCULATION_PATH, ['listing_id' => $listingRef, 'pms' => $this->config->pmsName]);
    }

    /**
     * @param list<array<string, mixed>> $overrides date rules in the API's fields (OverrideWrite::toArray())
     */
    public function writeOverrides(WriteToken $token, string $listingRef, array $overrides): void
    {
        $listingRef = self::listingRef($listingRef);
        $body = OverrideWriteBody::write($this->config->pmsName, $overrides);
        $this->authorise($token, new WriteRequest(WriteOperation::OverridesWrite, [$listingRef => OverrideWriteBody::dates($body)], OverrideWriteBody::fields($body)));
        $this->client->write($token, 'POST', self::overridesPath($listingRef), $body);
    }

    /**
     * @param list<DateTimeImmutable> $dates
     */
    public function deleteOverrides(WriteToken $token, string $listingRef, array $dates): void
    {
        $listingRef = self::listingRef($listingRef);
        $keys = array_map(static fn (mixed $date): string => $date instanceof DateTimeImmutable ? Days::key($date) : '', $dates);
        $body = OverrideWriteBody::delete($this->config->pmsName, $keys);
        $this->authorise($token, new WriteRequest(WriteOperation::OverridesDelete, [$listingRef => $keys]));
        $this->client->write($token, 'DELETE', self::overridesPath($listingRef), $body);
    }

    /**
     * @param array<string, float> $fields "min", "base" and/or "max" => amount
     */
    public function updateListing(WriteToken $token, string $listingRef, array $fields): void
    {
        $listingRef = self::listingRef($listingRef);
        $body = ListingUpdateBody::build($listingRef, $this->config->pmsName, $fields);
        $this->authorise($token, new WriteRequest(WriteOperation::ListingUpdate, [$listingRef => []], array_keys($fields)));
        $this->client->write($token, self::LISTING_ROUTE[0], self::LISTING_ROUTE[1], $body);
    }

    /**
     * @throws WriteBlocked when the connector is read-only or the token does not allow the request
     */
    private function authorise(WriteToken $token, WriteRequest $request): void
    {
        if ($this->authority === null) {
            throw WriteBlocked::forRequest('POST', 'PriceLabs', 'the connector was built read-only');
        }
        $this->authority->verify($token, $request);
    }

    private static function overridesPath(string $listingRef): string
    {
        return '/listings/' . rawurlencode($listingRef) . '/overrides';
    }

    /**
     * Every listing of the account, as the API sends them.
     *
     * @return list<array<int|string, mixed>>
     *
     * @throws ConnectorException
     */
    private function readDirectory(): array
    {
        $answer = $this->client->get('/listings', ['skip_hidden' => false]);
        if (!is_array($answer['listings'] ?? null)) {
            throw HttpFailure::unexpectedShape(self::ID, self::LABEL, 'GET /listings', 'there is no list of listings');
        }

        return Payload::rows($answer, 'listings');
    }

    /**
     * @param array<int|string, mixed> $listing
     */
    private function belongsToPms(array $listing): bool
    {
        return ListingMapper::pmsOf($listing) === $this->config->pmsName;
    }

    /**
     * Maps the rows, keeps the nights of the range and sorts them by date; of two rows for the
     * same night the last one stays. Rows the mapper cannot read are counted in a warning.
     *
     * @template T of PriceNight|DateOverride
     *
     * @param list<array<int|string, mixed>>            $rows
     * @param callable(array<int|string, mixed>): ?T    $map
     *
     * @return list<T>
     */
    private function inRange(array $rows, callable $map, DateRange $range, string $operation): array
    {
        $byNight = [];
        $unreadable = 0;
        foreach ($rows as $row) {
            $night = $map($row);
            if ($night === null) {
                ++$unreadable;
            } elseif ($range->contains($night->date)) {
                $byNight[Days::key($night->date)] = $night;
            }
        }
        if ($unreadable > 0) {
            $this->logger->warning('PriceLabs sent rows without a date or a price; they were left out.', [
                'provider' => self::ID,
                'operation' => $operation,
                'rows' => $unreadable,
            ]);
        }

        ksort($byNight);

        return array_values($byNight);
    }

    /**
     * @throws InvalidArgumentException on an empty or malformed listing id
     */
    private static function listingRef(string $listingRef): string
    {
        $listingRef = trim($listingRef);
        if ($listingRef === '' || strlen($listingRef) > 128 || preg_match('/[\x00-\x20\x7f\/\\\\?#]/', $listingRef) === 1) {
            throw new InvalidArgumentException('A PriceLabs listing id cannot be empty or contain spaces, slashes or control characters.');
        }

        return $listingRef;
    }
}
