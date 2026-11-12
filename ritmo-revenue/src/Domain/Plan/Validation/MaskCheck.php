<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

use Ritmo\Domain\Calendar\Stay\StayRules;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Orders\OrderRelease;
use Ritmo\Domain\Plan\OrphanClaimKind;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Support\Days;

/**
 * The arrival and departure days an item writes (seven 0/1 characters from Monday; a mask the
 * item leaves out is written all open). The character of the night's weekday is what counts:
 *
 * - a mask with every day closed is refused by the pricing system;
 * - on a closed day, opening a side the rule closes needs an order that releases the arrival and
 *   departure days, or an order that releases orphan stays with the matching orphan claim (and
 *   then only that side);
 * - on any other night, closing it needs an order that releases the days; on an enforced open
 *   day the same order is what closes its departure again.
 *
 * Reference: validar_plano.Validador.validar_chegada_saida.
 */
final class MaskCheck implements ItemCheck
{
    private const OPEN = '1111111';

    public function __construct(private readonly ?StayRules $stay, private readonly IssueLog $log)
    {
    }

    public function check(ItemFacts $f): void
    {
        $item = $f->item;
        if (!$item->setsDays()) {
            return;
        }
        $weekday = Days::weekday($item->date);
        foreach (['check_in' => $item->checkInMask, 'check_out' => $item->checkOutMask] as $side => $mask) {
            $mask ??= self::OPEN;
            if ($mask === '0000000') {
                $this->log->item($f, true, PlanIssue::MASK_ALL_CLOSED, sprintf('The %s mask closes every day: the pricing system refuses it.', $side), ['side' => $side]);

                return;
            }
            $open = $mask[$weekday] === '1';
            if ($this->closedDayCloses($f, $side)) {
                if ($open) {
                    $this->openingClosedDay($f, $side);
                }
                continue;
            }
            if (!$open) {
                $this->closing($f, $side);
            }
        }
    }

    private function closedDayCloses(ItemFacts $f, string $side): bool
    {
        $rule = $this->stay?->closedDay($f->item->roomCode, $f->item->date);
        if ($rule === null) {
            return false;
        }
        $restriction = $rule->openDate($f->item->date) ?? $rule->restriction;

        return $restriction === Restriction::NoCheckinCheckout
            || ($side === 'check_in' ? $restriction === Restriction::NoCheckin : $restriction === Restriction::NoCheckout);
    }

    private function openingClosedDay(ItemFacts $f, string $side): void
    {
        $authority = $f->authority;
        if ($authority->allows(OrderRelease::ArrivalDeparture)) {
            return;
        }
        if (!$authority->allows(OrderRelease::OrphanStay)) {
            $this->log->item($f, true, PlanIssue::MASK_OPENS_CLOSED_DAY, sprintf('The %s mask opens a closed day; only an owner order that releases the arrival and departure days does that.', $side), ['side' => $side, 'order_id' => $authority->id()]);

            return;
        }
        $kind = $f->item->orphan?->kind;
        $allowed = $side === 'check_in' ? OrphanClaimKind::ClosedDayArrival : OrphanClaimKind::ClosedDayDeparture;
        if ($kind !== $allowed) {
            $this->log->item($f, true, PlanIssue::MASK_BEYOND_ORPHAN_SIDE, sprintf('Order %s opens only the orphan side of the closed day; the %s mask goes beyond it.', $authority->id(), $side), ['side' => $side, 'order_id' => $authority->id()]);
        }
    }

    /**
     * Closing a night needs an order that releases the days; with an order that does not, an
     * enforced open day is named as such.
     */
    private function closing(ItemFacts $f, string $side): void
    {
        if ($f->authority->allows(OrderRelease::ArrivalDeparture)) {
            return;
        }
        $openDay = $f->authority->isValid() && $side === 'check_out' && $this->stay?->openDayApplies($f->item->roomCode, $f->item->date) === true;
        $this->log->item(
            $f,
            true,
            $openDay ? PlanIssue::MASK_CLOSES_OPEN_DAY : PlanIssue::MASK_CLOSES_OPEN_NIGHT,
            $openDay
                ? 'The departure of this day is kept open for the room; only an owner order that releases the arrival and departure days closes it again.'
                : sprintf('The %s mask closes a night that is not a closed day; only an owner order that releases the arrival and departure days does that.', $side),
            ['side' => $side, 'order_id' => $f->authority->id()],
        );
    }
}
