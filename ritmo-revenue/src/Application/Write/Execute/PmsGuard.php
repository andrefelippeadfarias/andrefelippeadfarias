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
 * - a change whose minimum stay read when it was planned is not the one there now is skipped:
 *   already_at_target, or changed_since_read (re-plan with the new calendar).
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
        if ($change->minStayBefore === null) {
            return null;
        }
        $differs = $atTarget = false;
        foreach ($change->nights() as $night) {
            $now = $fresh->pms[$change->roomId][$night]['minStay'] ?? null;
            if ($now !== $change->minStayBefore) {
                $differs = true;
                $atTarget = $atTarget || $now === $change->minStay;
            }
        }

        return $differs ? ($atTarget ? self::AT_TARGET : self::CHANGED) : null;
    }
}
