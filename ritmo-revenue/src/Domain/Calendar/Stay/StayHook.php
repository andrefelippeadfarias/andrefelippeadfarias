<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Support\Days;

/**
 * What the pricing plan asks of the stay rules when it deletes a date rule and writes it again
 * (to free a ceiling): the stay the rewritten rule must carry instead of the inherited one, the
 * proof when that stay is an orphan's, and the enforced open day in the inherited masks.
 *
 * Reference: calendario_12m.estadia_da_noite and prova_sabado_orfao, as pace.ajustar_tetos uses
 * them.
 */
final class StayHook
{
    public function __construct(private readonly StayRules $rules)
    {
    }

    /**
     * The stay the arrival rule plans from the current one, with the proof of an orphan night.
     *
     * @return array{PlannedStay, OrphanProof|null}
     */
    public function nightStay(string $room, DateTimeImmutable $night, ?int $current, Occupancy $occupancy): array
    {
        $planned = $this->rules->plannedStay($room, $night, $current, $occupancy->free($night), $occupancy->soldOut(Days::add($night, 1)));

        return [$planned, $planned->orphan ? $occupancy->proof($night, OrphanProof::NEXT) : null];
    }

    /**
     * The proof that a closed day is the free side of its orphan side (the night before sold out,
     * or past on today's closed day), or null when it is not.
     */
    public function closedDayOrphanProof(string $room, DateTimeImmutable $night, Occupancy $occupancy, ?DateTimeImmutable $today): ?OrphanProof
    {
        $closed = $this->rules->closedDay($room, $night);
        if ($closed === null || !$closed->orphanSide->enabled || !$this->rules->orphanAllowed($room, $night, true) || !$occupancy->free($night)) {
            return null;
        }
        if ($occupancy->soldOut(Days::add($night, -1))) {
            return $occupancy->proof($night, OrphanProof::PREVIOUS);
        }

        return $this->rules->closedDayWithPreviousPast($room, $night, $today) ? $occupancy->proofPreviousPast($night) : null;
    }

    /**
     * The inherited stay and departure mask of a rewritten date rule, corrected by the stay rules.
     */
    public function rewrite(string $room, DateTimeImmutable $night, ?int $minStay, ?string $checkoutMask, Occupancy $occupancy, ?DateTimeImmutable $today): RewrittenStay
    {
        $weekday = Days::weekday($night);
        if ($checkoutMask !== null && DateOverride::isValidMask($checkoutMask) && $checkoutMask[$weekday] === '0' && $this->rules->openDayApplies($room, $night)) {
            $checkoutMask = substr_replace($checkoutMask, '1', $weekday, 1);
        }
        [$planned, $proof] = $this->nightStay($room, $night, $minStay, $occupancy);
        $orphan = null;
        if ($planned->minStay !== null) {
            $minStay = $planned->minStay;
            [$orphan, $proof] = $planned->orphan && $minStay === 1 ? [OrphanKind::NextNight, $proof] : [null, null];
        }
        $own = $this->rules->closedDay($room, $night)?->minStay;
        if ($own !== null && ($minStay === null || $minStay < $own)) {
            $side = $this->closedDayOrphanProof($room, $night, $occupancy, $today);
            if ($side === null) {
                $minStay = max($own, $this->rules->requiredStay($room, $night) ?? $own);
            } elseif ($minStay !== null) {
                [$orphan, $proof] = [OrphanKind::ClosedDay, $side];
            }
        }

        return new RewrittenStay($minStay, $checkoutMask, $orphan, $proof);
    }
}
