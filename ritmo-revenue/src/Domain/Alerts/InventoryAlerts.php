<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

use DateTimeImmutable;
use Ritmo\Domain\Calendar\Stay\ArrivalDepartureChange;
use Ritmo\Domain\Calendar\Stay\RestrictedNight;
use Ritmo\Domain\Calendar\Stay\RoomAudit;
use Ritmo\Domain\Calendar\Stay\StayChange;
use Ritmo\Domain\Report\OverbookedNight;
use Ritmo\Domain\Support\Days;

/**
 * The alerts of the PMS inventory: overbooked nights, nights closed without a booking and the
 * findings of the inventory audit (one alert per room and kind of finding, nights listed).
 */
final class InventoryAlerts
{
    /**
     * @param list<OverbookedNight> $nights
     *
     * @return list<AlertDraft>
     */
    public static function overbooked(string $propertyId, array $nights): array
    {
        return array_map(static fn (OverbookedNight $n): AlertDraft => new AlertDraft(
            AlertCode::Overbooked,
            $propertyId,
            [$n->roomCode, Days::key($n->night)],
            'A night is sold beyond the units of the room.',
            ['units_over' => $n->unitsOver()],
            $n->roomCode,
            $n->night,
        ), $nights);
    }

    /**
     * @param list<ClosedNight> $nights
     *
     * @return list<AlertDraft>
     */
    public static function closedWithoutBooking(string $propertyId, array $nights): array
    {
        return array_map(static fn (ClosedNight $n): AlertDraft => new AlertDraft(
            AlertCode::InventoryClosedWithoutBooking,
            $propertyId,
            [$n->roomCode, Days::key($n->night)],
            'A night is closed in the PMS without a booking; it is left out of the plan until it opens.',
            ['off_sale' => $n->offSale, 'booked' => $n->booked, 'blackout' => $n->blackout],
            $n->roomCode,
            $n->night,
        ), $nights);
    }

    /**
     * @param list<RoomAudit> $audits
     *
     * @return list<AlertDraft>
     */
    public static function audit(string $propertyId, array $audits, int $maxNights): array
    {
        $drafts = [];
        foreach ($audits as $audit) {
            $stays = [
                ...array_map(static fn (StayChange $c): DateTimeImmutable => $c->night, [...$audit->orphanStays, ...$audit->packageStays, ...$audit->arrivalStays]),
                ...$audit->closedDayStayBelow,
            ];
            $groups = [
                ['stay', AlertCode::InventoryStayOffRule, null, $stays, 'Stays in the PMS are off the stay rules.'],
                ['arrival', AlertCode::InventoryArrivalOffRule, null, array_map(static fn (ArrivalDepartureChange $c): DateTimeImmutable => $c->night, $audit->arrivalChanges), 'Arrival or departure restrictions in the PMS are off the rules.'],
                ['other_restricted', AlertCode::InventoryArrivalOffRule, AlertSeverity::Info, array_map(static fn (RestrictedNight $n): DateTimeImmutable => $n->night, $audit->otherRestricted), 'Nights in the PMS carry restrictions the rules do not set.'],
            ];
            foreach ($groups as [$kind, $code, $severity, $nights, $message]) {
                if ($nights !== []) {
                    $drafts[] = self::auditDraft($propertyId, $audit->roomCode, $kind, $code, $severity, $nights, $message, $maxNights);
                }
            }
        }

        return $drafts;
    }

    /**
     * @param list<DateTimeImmutable> $nights
     */
    private static function auditDraft(string $propertyId, string $room, string $kind, AlertCode $code, ?AlertSeverity $severity, array $nights, string $message, int $maxNights): AlertDraft
    {
        $keys = array_values(array_unique(array_map(static fn (DateTimeImmutable $n): string => Days::key($n), $nights)));
        sort($keys);

        return new AlertDraft(
            $code,
            $propertyId,
            [$room, $kind],
            $message,
            ['finding' => $kind, 'count' => count($keys), 'nights' => array_slice($keys, 0, $maxNights)],
            $room,
            severity: $severity,
        );
    }
}
