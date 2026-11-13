<?php

declare(strict_types=1);

namespace Ritmo\Application\Verify;

use DateTimeImmutable;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\PmsConnector;
use Ritmo\Contracts\PricingConnector;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\ListingInfo;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Confirm\IsolatedNight;
use Ritmo\Domain\Write\Confirm\LiveCheck;
use Ritmo\Domain\Write\Confirm\LiveRoute;
use Ritmo\Domain\Write\Confirm\LiveStatus;
use Ritmo\Domain\Write\Confirm\LiveVerdict;
use Ritmo\Domain\Write\Confirm\StoredCheck;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\State\WrittenNight;

/**
 * Confirms what a write left, with reads only:
 *
 * - stored(): reads back every date rule and PMS night written (one read per room and range, no
 *   quota) and compares it with what the write should have left;
 * - live(): for the rooms whose calendar was recalculated after the write, compares the
 *   recalculated nights with the written rules; the other rooms get the route: a recalculation
 *   the quota allows (the write path sends it as a write step, then calls live() again), or
 *   "not confirmed (recalculation limit)", never a wait loop.
 *
 * Reference: ler_recalculo.py, beds24._conferir, SKILL.md (confirmation after every write; a
 * sold-out night proves nothing; no recalculation slot means "not confirmed").
 */
final class Verifier
{
    public function __construct(
        private readonly PricingConnector $pricing,
        private readonly PmsConnector $pms,
        private readonly LiveCheck $check = new LiveCheck(),
    ) {
    }

    /**
     * @param list<WrittenNight>      $written
     * @param array<string, RoomType> $rooms room code => room type of the property
     *
     * @return list<NightConfirmation> in the order given
     */
    public function stored(array $written, array $rooms): array
    {
        $observed = $this->readBack($written, $rooms);

        return array_map(static function (WrittenNight $night) use ($observed): NightConfirmation {
            $fields = $observed[$night->key()] ?? null;
            if ($fields === null) {
                return new NightConfirmation($night, StoredStatus::Unread, NightFields::none());
            }
            $differences = StoredCheck::differences($night, $fields);

            return new NightConfirmation($night, $differences === [] ? StoredStatus::Stored : StoredStatus::Mismatch, $fields, $differences);
        }, $written);
    }

    /**
     * @param list<NightConfirmation> $confirmations
     * @param array<string, RoomType> $rooms
     * @param list<ListingInfo>       $listings the listing directory read after the writes
     *
     * @return list<NightConfirmation> in the order given; stored date rules carry their route and,
     *                                 when compared, their verdict
     */
    public function live(array $confirmations, array $rooms, array $listings, ConfirmationQuota $quota, DateTimeImmutable $now): array
    {
        $byRoom = [];
        foreach ($confirmations as $i => $c) {
            if ($c->isStored() && $c->written->provider === Provider::Pricing && isset($rooms[$c->written->roomCode])) {
                $byRoom[$c->written->roomCode][$i] = $c;
            }
        }
        $refreshed = [];
        foreach ($listings as $listing) {
            $refreshed[$listing->ref] = $listing->sync->lastRefreshedAt;
        }
        foreach ($byRoom as $code => $group) {
            $room = $rooms[$code];
            $writtenAt = max(array_map(static fn (NightConfirmation $c): DateTimeImmutable => $c->written->writtenAt, $group));
            $route = LiveRoute::for($writtenAt, $refreshed[$room->pricingRef] ?? null, $quota->allows($code, $now));
            $verdicts = $route === LiveRoute::CompareNow ? $this->compare($room, $group) : [];
            foreach ($group as $i => $c) {
                $confirmations[$i] = $c->withLive($route, $verdicts[$i] ?? null);
            }
        }

        return $confirmations;
    }

    /**
     * @param array<int, NightConfirmation> $group the stored date rules of one room
     *
     * @return array<int, LiveVerdict>
     */
    private function compare(RoomType $room, array $group): array
    {
        $nights = array_map(static fn (NightConfirmation $c): DateTimeImmutable => $c->written->night, $group);
        try {
            $read = $this->pricing->readPrices($room->pricingRef, Days::add(min($nights), -1), Days::add(max($nights), 1));
        } catch (ConnectorException) {
            return array_map(static fn (): LiveVerdict => new LiveVerdict(LiveStatus::NoData, false), $group);
        }
        $calendar = [];
        foreach ($read as $night) {
            $calendar[Days::key($night->date)] = $night;
        }

        return array_map(fn (NightConfirmation $c): LiveVerdict => $this->check->check(
            $c->written,
            $room->base,
            $calendar[Days::key($c->written->night)] ?? null,
            IsolatedNight::is($calendar, $c->written->night),
        ), $group);
    }

    /**
     * The nights read back, by WrittenNight::key(); a night whose read failed is absent.
     *
     * @param list<WrittenNight>      $written
     * @param array<string, RoomType> $rooms
     *
     * @return array<string, NightFields>
     */
    private function readBack(array $written, array $rooms): array
    {
        $groups = [];
        foreach ($written as $night) {
            if (isset($rooms[$night->roomCode])) {
                $groups[$night->provider->value . '|' . $night->roomCode][] = $night;
            }
        }
        $observed = [];
        foreach ($groups as $group) {
            $first = $group[0];
            $room = $rooms[$first->roomCode];
            $nights = array_map(static fn (WrittenNight $n): DateTimeImmutable => $n->night, $group);
            try {
                $read = $first->provider === Provider::Pricing
                    ? self::overrides($this->pricing->readOverrides($room->pricingRef, min($nights), max($nights)))
                    : self::inventory($this->pms->readInventory([$room->pmsRef], min($nights), max($nights))[$room->pmsRef] ?? []);
            } catch (ConnectorException) {
                continue;
            }
            foreach ($group as $night) {
                $observed[$night->key()] = $read[Days::key($night->night)] ?? NightFields::none();
            }
        }

        return $observed;
    }

    /**
     * @param list<DateOverride> $overrides
     *
     * @return array<string, NightFields>
     */
    private static function overrides(array $overrides): array
    {
        $byDate = [];
        foreach ($overrides as $override) {
            $byDate[Days::key($override->date)] = NightFields::fromOverride($override);
        }

        return $byDate;
    }

    /**
     * @param list<InventoryNight> $nights
     *
     * @return array<string, NightFields>
     */
    private static function inventory(array $nights): array
    {
        $byDate = [];
        foreach ($nights as $night) {
            $byDate[Days::key($night->date)] = NightFields::fromInventory($night);
        }

        return $byDate;
    }
}
