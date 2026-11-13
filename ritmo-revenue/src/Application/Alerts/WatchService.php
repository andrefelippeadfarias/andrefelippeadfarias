<?php

declare(strict_types=1);

namespace Ritmo\Application\Alerts;

use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertRecord;
use Ritmo\Domain\Alerts\AlertsConfig;
use Ritmo\Domain\Alerts\BelowExpected;
use Ritmo\Domain\Alerts\BelowExpectedFinding;
use Ritmo\Domain\Alerts\ClosedWithoutBooking;
use Ritmo\Domain\Alerts\InventoryAlerts;
use Ritmo\Domain\Alerts\PriceBasis;
use Ritmo\Domain\Alerts\SecurityAlerts;
use Ritmo\Domain\Alerts\SecurityFinding;
use Ritmo\Domain\Alerts\SecurityWatch;
use Ritmo\Domain\Calendar\Stay\RoomAudit;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Operations\MovementClassifier;
use Ritmo\Domain\Report\OverbookingDetector;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Write\Hold\CancellationWave;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;

/**
 * The watch of every run, before any decision (O21): the security watch and the bookings below
 * expected over the one movement read, the overbooked nights and the nights closed without a
 * booking over the inventory read. It raises their alerts, resolves the inventory conditions the
 * run no longer finds in what it read, and hands the closed nights to the plan as blocked.
 */
final class WatchService
{
    public function __construct(
        private readonly AlertService $alerts,
        private readonly AlertsConfig $config,
        private readonly CancellationWave $waves,
        private readonly InventorySnapshotRepository $snapshots,
    ) {
    }

    public function watch(WatchInput $in): WatchOutcome
    {
        $watch = new SecurityWatch($this->config->security, $this->waves);
        $security = $watch->inspect($in->bookings, $in->since);
        $below = $this->belowExpected($in);
        $overbooked = (new OverbookingDetector())->detect(array_map('array_values', $in->inventory));
        $closed = ClosedWithoutBooking::find($in->inventory, $in->bookedUnits, $in->roomUnits);

        $this->alerts->raiseAll([
            ...SecurityAlerts::fromFindings($in->propertyId, $security),
            ...SecurityAlerts::belowExpected($in->propertyId, $below),
        ], $in->runId);
        $raised = $this->alerts->raiseAll([
            ...InventoryAlerts::overbooked($in->propertyId, $overbooked),
            ...InventoryAlerts::closedWithoutBooking($in->propertyId, $closed),
        ], $in->runId);
        $this->alerts->settle(
            $in->propertyId,
            [AlertCode::Overbooked, AlertCode::InventoryClosedWithoutBooking],
            $raised,
            self::within(array_keys($in->inventory), $in->checked),
        );

        return new WatchOutcome(
            $security,
            $below,
            $overbooked,
            $closed,
            ClosedWithoutBooking::blocked($closed),
            array_values(array_filter(array_map(static fn (SecurityFinding $f) => $f->wave, $security))),
            $this->config->security->originsConfirmed ? [] : $watch->originsSeen($in->bookings, $in->since),
        );
    }

    /**
     * The alerts of an inventory audit: one per room and kind of finding; the audit conditions of
     * the audited rooms it no longer finds are resolved.
     *
     * @param list<RoomAudit> $audits the audit of $rooms over $checked
     * @param list<string>    $rooms
     */
    public function audit(string $propertyId, array $audits, array $rooms, ?int $runId = null): void
    {
        $raised = $this->alerts->raiseAll(InventoryAlerts::audit($propertyId, $audits, $this->config->inventoryMaxNightsListed), $runId);
        $this->alerts->settle(
            $propertyId,
            [AlertCode::InventoryStayOffRule, AlertCode::InventoryArrivalOffRule],
            $raised,
            static fn (AlertRecord $a): bool => in_array($a->roomCode, $rooms, true),
        );
    }

    /**
     * @return list<BelowExpectedFinding>
     */
    private function belowExpected(WatchInput $in): array
    {
        $settings = $this->config->belowExpected;
        if (!$settings->enabled) {
            return [];
        }
        $bookings = array_values(array_filter(
            $in->bookings,
            static fn (BookingRecord $b): bool => $b->status->occupies() && $b->roomCode !== null && MovementClassifier::changedSince($b, $in->since)
        ));
        $prices = [];
        foreach ($bookings as $booking) {
            foreach ($this->pricesOf($booking, $in->inventory, $settings->priceBasis) as $night => $price) {
                $prices[(string) $booking->roomCode][$night] = $price;
            }
        }

        return (new BelowExpected($settings))->evaluate($bookings, $prices, $in->propertyId);
    }

    /**
     * The PMS price of each night of a booking: as read now, or (at booking time) as the latest
     * inventory read at or before the booking was made, falling back to the price now.
     *
     * @param array<string, array<string, InventoryNight>> $inventory
     *
     * @return array<string, float|null>
     */
    private function pricesOf(BookingRecord $booking, array $inventory, PriceBasis $basis): array
    {
        $room = (string) $booking->roomCode;
        $nights = $booking->nights();
        $then = $basis === PriceBasis::AtBooking && $booking->createdAt !== null
            ? $this->snapshots->latest($room, $nights->first(), $nights->last(), $booking->createdAt)
            : [];
        $prices = [];
        foreach ($nights->keys() as $night) {
            $prices[$night] = $then[$night]->pmsPrice ?? $inventory[$room][$night]->pmsPrice ?? null;
        }

        return $prices;
    }

    /**
     * @param list<string> $rooms
     *
     * @return \Closure(AlertRecord): bool
     */
    private static function within(array $rooms, DateRange $checked): \Closure
    {
        return static fn (AlertRecord $a): bool => in_array($a->roomCode, $rooms, true)
            && $a->night !== null
            && $checked->contains($a->night);
    }
}
