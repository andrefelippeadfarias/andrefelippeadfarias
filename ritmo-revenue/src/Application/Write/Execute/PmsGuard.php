<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Execute;

use Ritmo\Application\Write\Journal\StoredState;
use Ritmo\Application\Write\Schedule\CalendarChange;
use Ritmo\Application\Write\Schedule\WriteStep;

/**
 * What of a PMS block may still be sent, decided right before it with the calendar read again:
 * - a value the pricing system also pushes to the PMS goes only after the pricing step of the
 *   same room and nights was accepted in this run (else the next push undoes it): pricing_first;
 * - a change whose stay values read when it was planned (minimum and maximum stay, arrival and
 *   departure restriction, a missing value included) are not the ones there now is skipped:
 *   already_at_target when the PMS already holds every value it sets, else changed_since_read
 *   (a person changed the night: re-plan with the new calendar, never write over it).
 * Reference: beds24.editar_lote (min_stay_antes); AP 01/11 (the push undid PMS-only writes).
 */
final class PmsGuard
{
    public const PRICING_FIRST = 'pricing_first';
    public const AT_TARGET = 'already_at_target';
    public const CHANGED = 'changed_since_read';

    /**
     * @param array<string, array<string, true>> $pricingDone room code => nights whose pricing step was accepted
     *
     * @return array{0: WriteStep|null, 1: list<array{change: CalendarChange, reason: string}>} the step left (null: nothing) and the skipped changes
     */
    public function filter(WriteStep $step, array $pricingDone, StoredState $fresh): array
    {
        $kept = $skipped = [];
        foreach ($step->changes as $change) {
            $reason = $this->skipReason($change, $pricingDone, $fresh);
            if ($reason === null) {
                $kept[] = $change;
            } else {
                $skipped[] = ['change' => $change, 'reason' => $reason];
            }
        }
        if ($kept === []) {
            return [null, $skipped];
        }
        $nights = [];
        foreach ($kept as $change) {
            $nights[$change->roomId] = [...($nights[$change->roomId] ?? []), ...$change->nights()];
        }

        return [new WriteStep($step->number, $step->operation, $step->roomCode, $nights, array_map(static fn (CalendarChange $c): array => $c->range(), $kept), $step->label, '', $kept), $skipped];
    }

    /**
     * @param array<string, array<string, true>> $pricingDone
     */
    private function skipReason(CalendarChange $change, array $pricingDone, StoredState $fresh): ?string
    {
        foreach ($change->nights() as $night) {
            if ($change->alsoPricing && !isset($pricingDone[$change->roomCode][$night])) {
                return self::PRICING_FIRST;
            }
        }
        $read = $change->readBefore();
        if ($read === []) {
            return null;
        }
        $differs = false;
        $atTarget = true;
        foreach ($change->nights() as $night) {
            $now = $fresh->pms[$change->roomId][$night] ?? [];
            foreach ($read as $field => $before) {
                $value = self::normal($field, $now[$field] ?? null);
                if ($value !== self::normal($field, $before)) {
                    $differs = true;
                }
                $atTarget = $atTarget && $value === self::normal($field, $change->sets($field));
            }
        }

        return $differs ? ($atTarget ? self::AT_TARGET : self::CHANGED) : null;
    }

    /**
     * A minimum stay of none is a minimum of one night.
     */
    private static function normal(string $field, int|string|null $value): int|string|null
    {
        return $field === 'minStay' ? ($value ?? 1) : $value;
    }
}
