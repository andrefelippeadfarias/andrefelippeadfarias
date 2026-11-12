<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use DateTimeImmutable;
use Ritmo\Application\ConnectorSource;
use Ritmo\Contracts\PmsConnector;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;

/**
 * Reads what the run needs from the outside, only by read methods: the listings, overrides and
 * (when allowed) calendar of the pricing system, the PMS calendar of the run's nights and the
 * bookings changed since the last read. Stores the calendar as a snapshot and the bookings.
 */
final class CollectStage implements Stage
{
    public const NAME = 'collect';

    /** How far back the first read of bookings goes, when nothing was read before. */
    public const FIRST_BOOKINGS_READ_DAYS = 365;

    public function __construct(
        private readonly ConnectorSource $connectors,
        private readonly InventorySnapshotRepository $snapshots,
        private readonly BookingRepository $bookings,
        private readonly Clock $clock,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(RunContext $context): StageResult
    {
        $pms = $this->connectors->pms();
        $pricing = $this->connectors->pricing();
        $readsCalendar = $this->connectors->readsPricingCalendar();
        $from = $context->horizon->first();
        $to = $context->horizon->last();

        $byRef = [];
        foreach ($pricing->listListings() as $listing) {
            $byRef[$listing->ref] = $listing;
        }

        $listings = [];
        $overrides = [];
        $prices = [];
        $missing = [];
        foreach ($context->rooms as $room) {
            if ($room->pricingRef === '' || !isset($byRef[$room->pricingRef])) {
                $missing[] = $room->code;
                continue;
            }
            $listings[$room->code] = StateCodec::listing($byRef[$room->pricingRef]);
            foreach ($pricing->readOverrides($room->pricingRef, $from, $to) as $override) {
                $overrides[$room->code][Days::key($override->date)] = StateCodec::override($override);
            }
            if ($readsCalendar) {
                foreach ($pricing->readPrices($room->pricingRef, $from, $to) as $night) {
                    $prices[$room->code][Days::key($night->date)] = StateCodec::priceNight($night);
                }
            }
        }

        $inventory = $this->inventory($pms, $context, $from, $to);
        $since = $this->bookings->latestModification($pms->id()) ?? $this->clock->now()->modify(sprintf('-%d days', self::FIRST_BOOKINGS_READ_DAYS));
        $bookings = $pms->readBookingsModifiedSince($since);
        $capturedAt = $this->clock->now();

        return new StageResult(
            [
                'rooms' => count($context->rooms),
                'listings_found' => count($listings),
                'listings_missing' => $missing,
                'overrides_read' => array_sum(array_map('count', $overrides)),
                'pricing_calendar_read' => $readsCalendar,
                'pricing_nights_read' => array_sum(array_map('count', $prices)),
                'inventory_nights_read' => array_sum(array_map('count', $inventory)),
                'bookings_read' => count($bookings),
                'bookings_read_since' => $since->format(DATE_ATOM),
            ],
            [
                'captured_at' => $capturedAt->format(DATE_ATOM),
                'pricing_calendar_read' => $readsCalendar,
                'listings' => $listings,
                'overrides' => $overrides,
                'prices' => $prices,
            ],
            function () use ($context, $inventory, $bookings, $capturedAt, $pms): void {
                foreach ($inventory as $code => $nights) {
                    $this->snapshots->store($code, $nights, $capturedAt, $context->runId);
                }
                $this->bookings->upsertMany($pms->id(), $bookings, $capturedAt);
            },
        );
    }

    /**
     * The PMS calendar of the mapped rooms, by room code.
     *
     * @return array<string, list<InventoryNight>>
     */
    private function inventory(PmsConnector $pms, RunContext $context, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $codes = [];
        foreach ($context->rooms as $room) {
            $codes[$room->pmsRef] = $room->code;
        }
        $read = $pms->readInventory(array_map('strval', array_keys($codes)), $from, $to, true);

        $inventory = [];
        foreach ($codes as $ref => $code) {
            $inventory[$code] = array_values($read[(string) $ref] ?? []);
        }

        return $inventory;
    }
}
