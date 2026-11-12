<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Orders\OrderRelease;
use Ritmo\Domain\Plan\PlanConfig;
use Ritmo\Domain\Plan\PlanIssue;

/**
 * The shape of the prices of an item against the night: tonight never rises, nothing over the
 * listing maximum, a ceiling never under the floor (the item's or, without one, the floor in
 * force), a room without discounts never under its current price, and the reason of a date rule
 * kept only by an item that does not change the price it explains.
 *
 * Reference: validar_plano.Validador.validar_data (max_price abaixo do piso, teto abaixo do piso
 * vigente, piso efetivo acima do máximo, sem_desconto, manter_reason) and the M1 rule of tonight.
 */
final class PriceBoundsCheck implements ItemCheck
{
    /** Slack over the listing maximum (the reference's). */
    private const LISTING_MAX_MARGIN = 0.5;

    /** Two amounts within one currency unit are the same price (the reference's). */
    private const SAME_AMOUNT = 1.0;

    public function __construct(
        private readonly RuleSet $rules,
        private readonly PlanConfig $config,
        private readonly IssueLog $log,
    ) {
    }

    public function check(ItemFacts $f): void
    {
        if ($f->daysOut === 0) {
            $this->tonight($f);
        }
        $this->listingMaximum($f);
        $this->ceilingAgainstFloor($f);
        $this->noDiscount($f);
        if ($f->item->keepReason && $f->item->setsPrice()) {
            $this->log->item($f, true, PlanIssue::KEEP_REASON_WITH_PRICE, 'An item that changes the floor or the ceiling cannot keep the reason of the date rule: the reason would explain another price.');
        }
    }

    /**
     * The price of tonight never goes up: a floor above tonight's price would raise it, and so
     * would one that only the rounding up to a whole percentage of the base puts above it. The
     * price compared is the lowest known (the item's record or the fresh calendar). A floor that
     * writes again the floor in force changes nothing and raises nothing.
     */
    private function tonight(ItemFacts $f): void
    {
        $item = $f->item;
        if ($item->minPrice === null || !$this->rules->neverRaiseToday() || $f->rewritesFloorInForce()) {
            return;
        }
        $current = $f->lowestCurrent();
        if ($current === null) {
            $this->log->item($f, true, PlanIssue::TODAY_WITHOUT_CURRENT_PRICE, 'A floor for tonight needs the current price of the night, to show that it does not raise it.');

            return;
        }
        $applied = $f->percent->minAmount(2);
        if ($item->minPrice > $current || $applied > $current) {
            $this->log->item($f, true, PlanIssue::RAISE_TODAY, sprintf('The floor of %.2f, applied as %.2f, would raise the price of tonight (%.2f).', $item->minPrice, $applied, $current), [
                'floor' => $item->minPrice,
                'applied_floor' => $applied,
                'current_price' => $current,
            ]);
        }
    }

    private function listingMaximum(ItemFacts $f): void
    {
        $limit = $f->room->max + self::LISTING_MAX_MARGIN;
        foreach (['floor' => $f->floor(), 'ceiling' => $f->item->maxPrice] as $what => $amount) {
            if ($amount !== null && $amount > $limit) {
                $this->log->item($f, true, PlanIssue::ABOVE_LISTING_MAX, sprintf('The %s of %.2f is over the listing maximum of %.2f.', $what, $amount, $f->room->max), [
                    'amount' => $amount,
                    'listing_max' => $f->room->max,
                ]);
            }
        }
    }

    /**
     * A ceiling under the floor leaves the pricing system at the floor: the ceiling would not do
     * what the plan says.
     */
    private function ceilingAgainstFloor(ItemFacts $f): void
    {
        $item = $f->item;
        if ($item->maxPrice === null) {
            return;
        }
        $floor = $item->minPrice;
        $inForce = false;
        if ($floor === null && ($f->floorInForce() ?? 0.0) > $item->maxPrice + self::SAME_AMOUNT) {
            $floor = $f->floorInForce();
            $inForce = true;
        }
        if ($floor !== null && $item->maxPrice < $floor) {
            $this->log->item($f, true, PlanIssue::CEILING_BELOW_FLOOR, sprintf('The ceiling of %.2f is under the floor %s, %.2f.', $item->maxPrice, $inForce ? 'in force' : 'of the item', $floor), [
                'ceiling' => $item->maxPrice,
                'floor' => $floor,
                'floor_in_force' => $inForce,
            ]);
        }
    }

    private function noDiscount(ItemFacts $f): void
    {
        if (!in_array($f->room->code, $this->config->noDiscountRooms, true) || $f->authority->allows(OrderRelease::BelowStandardFloor)) {
            return;
        }
        $current = $f->current();
        if ($current === null) {
            $this->log->item($f, true, PlanIssue::NO_DISCOUNT_ROOM, 'This room takes no discount, and without its current price the item cannot show it is not one.', ['current_price' => null]);

            return;
        }
        foreach (['floor' => $f->floor(), 'ceiling' => $f->item->maxPrice] as $what => $amount) {
            if ($amount !== null && $amount < $current - self::SAME_AMOUNT) {
                $this->log->item($f, true, PlanIssue::NO_DISCOUNT_ROOM, sprintf('This room takes no discount: the %s of %.2f is under its current price, %.2f.', $what, $amount, $current), [
                    'amount' => $amount,
                    'current_price' => $current,
                ]);
            }
        }
    }
}
