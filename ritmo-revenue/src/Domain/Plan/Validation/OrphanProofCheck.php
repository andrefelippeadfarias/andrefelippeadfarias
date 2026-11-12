<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

use DateTimeImmutable;
use Ritmo\Domain\Calendar\Stay\OrphanProof;
use Ritmo\Domain\Plan\FreshCalendar;
use Ritmo\Domain\Support\Days;

/**
 * Whether the occupancy an item carries proves its orphan night, and whether the fresh calendar
 * contradicts it.
 *
 * A proof from the PMS inventory stands on its own when it was read today or yesterday (in the
 * property's timezone): the night has units for sale and the neighbour none. A proof from the
 * pricing calendar shows the night with a unit free and the neighbour sold out. On today's closed
 * day whose previous night has passed, that night counts as sold out and only the closed day's
 * free unit is checked.
 *
 * Reference: validar_plano.checar_prova_orfa, _checar_sexta_passada and _falha_lido_em.
 */
final class OrphanProofCheck
{
    public function __construct(
        private readonly DateTimeImmutable $today,
        private readonly string $timezone,
        private readonly ?FreshCalendar $calendar,
    ) {
    }

    /**
     * Why the proof does not prove the orphan, or null when it does.
     *
     * @param string $neighbour OrphanProof::NEXT or OrphanProof::PREVIOUS: the night that sold out
     */
    public function failure(?OrphanProof $proof, string $neighbour, DateTimeImmutable $night, bool $closedDay): ?string
    {
        if ($proof === null) {
            return 'no proof';
        }
        if ($neighbour === OrphanProof::PREVIOUS && $proof->previousPast !== null) {
            return $this->previousPast($proof, $night, $closedDay);
        }
        if ($proof->neighbour !== $neighbour) {
            return 'the proof is of the other neighbour';
        }
        if ($proof->source === OrphanProof::SOURCE_PMS) {
            if ($proof->units === null || $proof->nightAvailable === null || $proof->neighbourAvailable === null) {
                return 'incomplete inventory proof';
            }
            if (!($proof->units > 0 && $proof->nightAvailable > 0 && $proof->neighbourAvailable <= 0)) {
                return 'the inventory does not show the night free with its neighbour sold out';
            }

            return $this->readFailure($proof);
        }
        if ($proof->nightSold === null || $proof->nightTotal === null || $proof->neighbourSold === null || $proof->neighbourTotal === null) {
            return 'incomplete calendar proof';
        }
        if (!($proof->neighbourTotal > 0 && $proof->neighbourSold >= $proof->neighbourTotal && $proof->nightTotal > 0 && $proof->nightSold < $proof->nightTotal)) {
            return 'the calendar does not show the night free with its neighbour sold out';
        }

        return null;
    }

    /**
     * True when the fresh calendar shows the neighbour with a unit free or the night sold out.
     */
    public function contradicted(string $room, DateTimeImmutable $night, string $neighbour, bool $previousPast): bool
    {
        $own = $this->calendar?->night($room, $night);
        if ($own !== null && $own->free() <= 0) {
            return true;
        }
        if ($previousPast) {
            return false;
        }
        $other = $this->calendar?->night($room, Days::add($night, $neighbour === OrphanProof::NEXT ? 1 : -1));

        return $other !== null && $other->free() > 0;
    }

    private function previousPast(OrphanProof $proof, DateTimeImmutable $night, bool $closedDay): ?string
    {
        if (!$closedDay || Days::key($proof->previousPast) !== Days::key(Days::add($night, -1))) {
            return 'the past night is not the night before the closed day';
        }
        if (Days::diff($proof->previousPast, $this->today) <= 0) {
            return 'the night before has not passed yet';
        }
        if (Days::diff($this->today, $night) < 0) {
            return 'the closed day has passed';
        }
        if ($proof->source === OrphanProof::SOURCE_PMS) {
            if ($proof->units === null || $proof->nightAvailable === null) {
                return 'incomplete inventory proof';
            }

            return $proof->units > 0 && $proof->nightAvailable > 0 ? $this->readFailure($proof) : 'the inventory does not show the closed day free';
        }
        if ($proof->nightSold === null || $proof->nightTotal === null) {
            return 'incomplete calendar proof';
        }

        return $proof->nightTotal > 0 && $proof->nightSold < $proof->nightTotal ? null : 'the calendar does not show the closed day free';
    }

    private function readFailure(OrphanProof $proof): ?string
    {
        if ($proof->readAt === null) {
            return 'the inventory proof has no read instant';
        }
        $days = Days::diff(Days::localDate($proof->readAt, $this->timezone), $this->today);

        return $days >= 0 && $days <= 1 ? null : 'the inventory was read too long ago; read it again';
    }
}
