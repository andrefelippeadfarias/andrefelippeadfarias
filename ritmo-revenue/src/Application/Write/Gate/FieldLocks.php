<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Gate;

use Ritmo\Application\Write\Schedule\WriteStep;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Domain\Model\RoomType;

/**
 * The first of the four field locks (the others: the connectors' body whitelists, the HTTP write
 * allow-list and the source tests): what each system may receive, checked over every step before
 * a token exists.
 * - Pricing system: never a price, a price type, a fixed amount or an expiry on a date rule.
 * - PMS: never a price; availability and channel limits from 0 to the units of the room; stays
 *   from 1 to 30 with the minimum not above the maximum.
 * Bookings have no step at all.
 */
final class FieldLocks
{
    private const PRICING_FORBIDDEN = ['price', 'price_type', 'lead_time_expiry'];

    private const PERCENT_TYPE = 'percent_base';

    private const MAX_STAY = 30;

    /**
     * @param list<WriteStep>         $steps
     * @param array<string, RoomType> $rooms room code => room
     *
     * @throws WriteRefused
     */
    public function check(array $steps, array $rooms): void
    {
        foreach ($steps as $step) {
            match ($step->operation) {
                WriteOperation::OverridesWrite => $this->pricing($step),
                WriteOperation::PmsCalendar => $this->pms($step, $rooms),
                default => null,
            };
        }
    }

    private function pricing(WriteStep $step): void
    {
        foreach ($step->payload as $entry) {
            foreach ($entry as $field => $value) {
                $forbidden = in_array($field, self::PRICING_FORBIDDEN, true) || (str_ends_with($field, '_type') && $value !== self::PERCENT_TYPE);
                if ($forbidden) {
                    throw self::refused(sprintf('field "%s" is never written to the pricing system', $field), $step);
                }
            }
        }
    }

    /**
     * @param array<string, RoomType> $rooms
     */
    private function pms(WriteStep $step, array $rooms): void
    {
        foreach ($step->changes as $change) {
            $room = $rooms[$change->roomCode] ?? throw self::refused('a PMS change of an unknown room', $step);
            foreach ($change->range() as $field => $value) {
                if (str_starts_with(strtolower($field), 'price')) {
                    throw self::refused('a price is never written to the PMS', $step);
                }
            }
            $within = static fn (?int $v, int $min, int $max): bool => $v === null || ($v >= $min && $v <= $max);
            $ok = $within($change->available, 0, $room->units)
                && $within($change->minStay, 1, self::MAX_STAY)
                && $within($change->maxStay, 1, self::MAX_STAY)
                && ($change->minStay === null || $change->maxStay === null || $change->minStay <= $change->maxStay);
            foreach ($change->channels as $limit) {
                $ok = $ok && $within($limit, 0, $room->units);
            }
            if (!$ok || $change->roomId !== $room->pmsRef) {
                throw self::refused('a PMS value out of range for the room', $step);
            }
        }
    }

    private static function refused(string $why, WriteStep $step): WriteRefused
    {
        return new WriteRefused(WriteRefused::FORBIDDEN_FIELD, ucfirst($why) . '.', $step->roomCode);
    }
}
