<?php

declare(strict_types=1);

namespace Ritmo\Application\Market;

use DateTimeImmutable;
use Ritmo\Domain\Market\Series\MarketConfig;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\StaySampleRepository;

/**
 * How fresh the market prices of a property are (spec 06, 1.7): the newest price captured for a
 * stay of the next market.reminder_horizon_days nights, its age in days and whether that is older
 * than market.stale_after_days. The full run's report stage turns a stale answer into the
 * market_stale alert (one open per property, notified at most once a week), and the market
 * screen shows the same sentence as a banner.
 */
final class MarketFreshness
{
    public function __construct(private readonly StaySampleRepository $samples)
    {
    }

    /**
     * @return array{stale: bool, newest: DateTimeImmutable|null, age_days: int|null}
     */
    public function check(string $propertyId, DateTimeImmutable $today, string $timezone, MarketConfig $config): array
    {
        $newest = $this->samples->newestCapture($propertyId, $today, Days::add($today, $config->reminderHorizonDays));
        if ($newest === null) {
            return ['stale' => true, 'newest' => null, 'age_days' => null];
        }
        $age = max(0, Days::diff(Days::localDate($newest, $timezone), $today));

        return ['stale' => $age > $config->staleAfterDays, 'newest' => $newest, 'age_days' => $age];
    }
}
