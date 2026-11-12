<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

use Ritmo\Domain\Calendar\Stay\ClosedDayRule;
use Ritmo\Domain\Calendar\Stay\OrphanProof;
use Ritmo\Domain\Calendar\Stay\StayRules;
use Ritmo\Domain\Orders\OrderRelease;
use Ritmo\Domain\Plan\OrphanClaimKind;
use Ritmo\Domain\Plan\PlanConfig;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Support\Days;

/**
 * The minimum stay an item writes against the stay rules of the night:
 *
 * - never under what a holiday, a package, the arrival rule or a closed day requires, unless the
 *   night is a proven orphan or an owner order releases the stay (an order older than the arrival
 *   rule does not);
 * - where the arrival rule opens one night, not above it without such an order (above a longer
 *   rule only a warning);
 * - a closed day or holiday night written without its stay gets a warning (the date rule may lose
 *   it);
 * - an orphan night needs its proof (see OrphanProofCheck), not contradicted by the fresh calendar.
 *
 * Reference: validar_plano.Validador.validar_data (the min_stay block), libera_estadia.
 */
final class StayCheck implements ItemCheck
{
    public function __construct(
        private readonly ?StayRules $stay,
        private readonly PlanConfig $config,
        private readonly OrphanProofCheck $proofs,
        private readonly IssueLog $log,
    ) {
    }

    public function check(ItemFacts $f): void
    {
        $item = $f->item;
        $room = $item->roomCode;
        $required = $this->stay?->requiredStay($room, $item->date);
        $closed = $this->stay?->closedDay($room, $item->date);
        $holiday = $f->holiday !== null;
        $ms = $item->minStay;
        if ($ms === null) {
            if ($required !== null && $item->minPrice !== null && ($holiday || $closed !== null)) {
                $this->log->item($f, false, PlanIssue::STAY_NOT_IN_ITEM, sprintf('The item writes no stay on a night that requires %d nights: check the stay after writing.', $required), ['required' => $required]);
            }

            return;
        }
        if ($ms > $this->config->maxMinStay) {
            $this->log->item($f, true, PlanIssue::STAY_OUT_OF_RANGE, sprintf('A minimum stay of %d nights is beyond the longest allowed, %d.', $ms, $this->config->maxMinStay), ['min_stay' => $ms]);
        }
        if ($this->stay === null) {
            return;
        }
        $release = $this->releasesStay($f);
        $orphan = $this->orphan($f, $ms, $required, $closed);
        $params = ['min_stay' => $ms, 'required' => $required];
        if (($holiday || $this->stay->isPackageNight($room, $item->date)) && $required !== null && $ms < $required && !$release && !$orphan) {
            $this->log->item($f, true, PlanIssue::STAY_BELOW_REQUIRED, sprintf('The night requires a stay of %d nights; the item writes %d.', $required, $ms), $params);
        }
        if ($required !== null && $this->stay->arrivalDecides($room, $item->date)) {
            $this->arrivalRule($f, $ms, $required, $release, $orphan);
        }
        if ($closed?->minStay !== null && $ms < $closed->minStay && !($orphan || (!$holiday && $release))) {
            $this->log->item($f, true, PlanIssue::STAY_BELOW_REQUIRED, sprintf('The closed day keeps a stay of %d nights; only a proven orphan or an owner order opens it.', $closed->minStay), ['min_stay' => $ms, 'required' => $closed->minStay]);
        }
    }

    private function arrivalRule(ItemFacts $f, int $ms, int $required, bool $release, bool $orphan): void
    {
        $params = ['min_stay' => $ms, 'required' => $required];
        if ($ms < $required) {
            if (!$orphan && !$release) {
                $this->log->item($f, true, PlanIssue::STAY_BELOW_REQUIRED, sprintf('An arrival on this weekday requires %d nights; the item writes %d.', $required, $ms), $params);
            }
        } elseif ($ms > $required && !$release) {
            $one = $required === 1;
            $this->log->item($f, $one, $one ? PlanIssue::STAY_ABOVE_ONE_NIGHT : PlanIssue::STAY_ABOVE_REQUIRED, sprintf('The arrival rule asks %d nights; the item writes %d.', $required, $ms), $params);
        }
    }

    /**
     * True when the item claims an orphan night and proves it; reports the claim that fails.
     */
    private function orphan(ItemFacts $f, int $ms, ?int $required, ?ClosedDayRule $closed): bool
    {
        $claim = $f->item->orphan;
        if ($claim === null || $this->stay === null) {
            return false;
        }
        $room = $f->item->roomCode;
        $night = $f->item->date;
        if ($claim->kind === OrphanClaimKind::NextNight) {
            if ($ms >= ($required ?? 1)) {
                return false;
            }
            if (!$this->stay->orphanDecides($room, $night) || $ms !== 1) {
                return $this->refuse($f, PlanIssue::ORPHAN_NOT_ALLOWED, 'An orphan night takes one night, where the orphan rule applies (not on a package, a closed day or, unless allowed, a holiday).');
            }

            return $this->proven($f, OrphanProof::NEXT, false);
        }
        if ($claim->kind !== OrphanClaimKind::ClosedDayArrival || $claim->proof === null || $ms >= ($closed?->minStay ?? $required ?? 1)) {
            return false;
        }
        if ($closed === null || !$this->stay->orphanAllowed($room, $night, true) || !$closed->orphanSide->enabled) {
            return $this->refuse($f, PlanIssue::ORPHAN_NOT_ALLOWED, 'Only a closed day with its orphan side on (not on a package or an open date; on a holiday only when allowed) opens for one night.');
        }

        return $this->proven($f, OrphanProof::PREVIOUS, true);
    }

    private function proven(ItemFacts $f, string $neighbour, bool $closedDay): bool
    {
        $proof = $f->item->orphan?->proof;
        $failure = $this->proofs->failure($proof, $neighbour, $f->item->date, $closedDay);
        if ($failure !== null) {
            return $this->refuse($f, PlanIssue::ORPHAN_WITHOUT_PROOF, sprintf('The orphan night is not proven: %s.', $failure));
        }
        if ($this->proofs->contradicted($f->item->roomCode, $f->item->date, $neighbour, $proof?->previousPast !== null)) {
            return $this->refuse($f, PlanIssue::ORPHAN_CONTRADICTED, 'The fresh calendar contradicts the orphan night: read the occupancy again.');
        }

        return true;
    }

    private function refuse(ItemFacts $f, string $code, string $message): bool
    {
        $this->log->item($f, true, $code, $message);

        return false;
    }

    /**
     * An owner order that releases the stay, given once the arrival rule was already in force.
     */
    private function releasesStay(ItemFacts $f): bool
    {
        if (!$f->authority->allows(OrderRelease::Stay)) {
            return false;
        }
        $rule = $this->stay?->config()->arrival;
        if ($rule === null || !$rule->enabled || $rule->validFrom === null) {
            return true;
        }
        $given = $f->authority->order?->givenOn;

        return $given !== null && Days::diff($rule->validFrom, $given) >= 0;
    }
}
