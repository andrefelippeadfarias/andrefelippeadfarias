<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use DateTimeImmutable;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;

/**
 * Scopes a light run (operations spec, section 4).
 *
 * The filter holds, for each room, the nights of the bookings that moved in [today, today +
 * horizon) (both the new and the previous version of a modified booking) and the nights that sold
 * out or reopened from today to today + protect_flip_days, even without a booking. A room whose
 * confirmation recalculation does not fit the quota keeps only its sold-out nights (protection,
 * a safety item); the rest waits for the next full run. Manual-change nights never enter the
 * filter: they are frozen.
 *
 * Reference: SKILL.md "Modo 2" (light routine), pace.ler_filtro_noites, pace.plano(noites_filtro).
 */
final class LightRoundPlanner
{
    public function __construct(private readonly LightRunConfig $config)
    {
    }

    public function plan(LightRoundFacts $facts): LightRoundPlan
    {
        $today = $facts->today;
        $movementNights = $this->movementNights($facts);
        $protect = self::within($facts->flips, $today, $this->config->protectFlipDays);
        $orphanFlips = self::within($facts->flips, $today, $this->config->orphanWindowDays);

        $candidates = $movementNights;
        foreach ($protect as $flip) {
            $candidates[$flip->roomCode][PlanItem::keyOf($flip->roomCode, $flip->night)] = true;
        }
        [$filter, $deferred, $paceRooms, $safetyOnly] = self::split($candidates, $facts);

        $orphanRooms = array_values(array_unique(array_map(static fn (SoldOutFlip $f): string => $f->roomCode, $orphanFlips)));
        sort($orphanRooms);
        $affected = array_map('strval', array_unique([...array_keys($movementNights), ...$orphanRooms, ...array_keys(array_filter($facts->manualChanges))]));
        sort($affected);

        return new LightRoundPlan(
            $this->triggers($movementNights, $orphanFlips, $facts),
            $filter,
            $affected,
            $paceRooms,
            $safetyOnly,
            $protect,
            $orphanRooms,
            new DateRange($today, Days::add($today, $this->config->orphanWindowDays)),
            $deferred,
        );
    }

    /**
     * The flips from today to today + $days.
     *
     * @param list<SoldOutFlip> $flips
     *
     * @return list<SoldOutFlip>
     */
    private static function within(array $flips, DateTimeImmutable $today, int $days): array
    {
        return array_values(array_filter($flips, static fn (SoldOutFlip $f): bool => Days::diff($today, $f->night) >= 0 && Days::diff($today, $f->night) <= $days));
    }

    /**
     * Splits the candidate nights by room: a room with quota takes all of them; a room without
     * keeps its nights sold out now (protection) and the others wait. Sold-out nights outside the
     * candidates wait for the full run's protection.
     *
     * @param array<string, array<string, true>> $candidates room code => PlanItem keys
     *
     * @return array{0: array<string, true>, 1: array<string, DeferredWork>, 2: list<string>, 3: list<string>}
     */
    private static function split(array $candidates, LightRoundFacts $facts): array
    {
        ksort($candidates);
        $filter = [];
        $deferred = [];
        $paceRooms = [];
        $safetyOnly = [];
        foreach ($candidates as $room => $keys) {
            $permitted = $facts->quota[(string) $room] ?? false;
            if ($permitted) {
                $paceRooms[] = (string) $room;
            } else {
                $safetyOnly[] = (string) $room;
            }
            foreach (array_keys($keys) as $key) {
                if ($permitted || isset($facts->soldOutNow[$key])) {
                    $filter[$key] = true;
                } else {
                    $deferred[$key] = DeferredWork::PaceWithoutQuota;
                }
            }
        }
        foreach (array_keys($facts->soldOutNow) as $key) {
            if (!isset($filter[$key]) && !isset($deferred[$key])) {
                $deferred[$key] = DeferredWork::ProtectionOutsideFilter;
            }
        }
        ksort($filter);
        ksort($deferred);

        return [$filter, $deferred, $paceRooms, $safetyOnly];
    }

    /**
     * room code => PlanItem keys of the moved nights inside the horizon.
     *
     * @return array<string, array<string, true>>
     */
    private function movementNights(LightRoundFacts $facts): array
    {
        $horizon = new DateRange($facts->today, Days::add($facts->today, $facts->horizonDays - 1));
        $nights = [];
        foreach ($facts->movements as $movement) {
            if (!$movement->touchesSales()) {
                continue;
            }
            self::addNights($nights, $movement->booking->roomCode, $movement->booking->nights(), $horizon);
            if ($movement->previous !== null) {
                self::addNights($nights, $movement->previous->roomCode, $movement->previous->nights(), $horizon);
            }
        }

        return $nights;
    }

    /**
     * @param array<string, array<string, true>> $nights
     */
    private static function addNights(array &$nights, ?string $room, DateRange $stay, DateRange $horizon): void
    {
        $inside = $room === null ? null : $stay->intersect($horizon);
        if ($inside === null) {
            return;
        }
        foreach ($inside as $night) {
            $nights[$room][PlanItem::keyOf($room, $night)] = true;
        }
    }

    /**
     * @param array<string, array<string, true>> $movementNights
     * @param list<SoldOutFlip>                  $orphanFlips
     *
     * @return list<LightTrigger>
     */
    private function triggers(array $movementNights, array $orphanFlips, LightRoundFacts $facts): array
    {
        $triggers = [];
        if ($movementNights !== []) {
            $triggers[] = LightTrigger::Movement;
        }
        if ($orphanFlips !== []) {
            $triggers[] = LightTrigger::SoldOutFlip;
        }
        if (array_filter($facts->manualChanges, static fn (array $nights): bool => $nights !== []) !== []) {
            $triggers[] = LightTrigger::ManualChange;
        }
        if ($facts->newOverbooked > 0) {
            $triggers[] = LightTrigger::Overbooking;
        }

        return $triggers;
    }
}
