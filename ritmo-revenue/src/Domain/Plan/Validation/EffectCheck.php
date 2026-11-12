<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

use DateTimeImmutable;
use Ritmo\Domain\Orders\OrderRelease;
use Ritmo\Domain\Plan\FreshCalendar;
use Ritmo\Domain\Plan\PlanConfig;
use Ritmo\Domain\Plan\PlanIssue;

/**
 * What an item changes and what it was decided on: a decision that depends on occupancy needs a
 * fresh calendar; an item that changes nothing of what is in force is refused; a floor far above
 * the current price gets a warning (the pricing system moves one step a day).
 *
 * Reference: validar_plano.Validador.calendario_fresco and the 15% warning of validar_data;
 * pace.sem_efeito for the effect.
 */
final class EffectCheck implements ItemCheck
{
    /** Two amounts within one currency unit are the same price (the reference's). */
    private const SAME_AMOUNT = 1.0;

    public function __construct(
        private readonly PlanConfig $config,
        private readonly ?FreshCalendar $calendar,
        private readonly bool $acceptStaleCalendar,
        private readonly DateTimeImmutable $now,
        private readonly IssueLog $log,
    ) {
    }

    public function check(ItemFacts $f): void
    {
        $this->calendarAge($f);
        $this->effect($f);
        $this->rise($f);
    }

    private function calendarAge(ItemFacts $f): void
    {
        $floor = $f->floor();
        if ($floor === null || $this->calendar === null) {
            return;
        }
        if ($floor >= $f->room->min && !$f->item->readsOccupancy && !$this->config->dependsOnOccupancy($f->item->reasonCode)) {
            return;
        }
        $age = $this->calendar->ageHours($f->room->code, $this->now);
        if ($age === null || $age <= $this->config->calendarMaxAgeHours) {
            return;
        }
        $params = ['age_hours' => $age, 'max_hours' => $this->config->calendarMaxAgeHours];
        if ($this->acceptStaleCalendar) {
            $this->log->item($f, false, PlanIssue::STALE_CALENDAR_ACCEPTED, sprintf('The calendar of this room is %.1f hours old (limit %s): accepted for this validation.', $age, $this->config->calendarMaxAgeHours), $params);
        } else {
            $this->log->item($f, true, PlanIssue::STALE_CALENDAR, sprintf('The calendar of this room is %.1f hours old (limit %s): recalculate it before deciding by occupancy.', $age, $this->config->calendarMaxAgeHours), $params);
        }
    }

    private function effect(ItemFacts $f): void
    {
        $state = $f->state;
        if ($state === null) {
            $this->log->item($f, false, PlanIssue::NO_BEFORE, 'Without what is in force for the night, the item cannot be checked for effect.');

            return;
        }
        $percent = $f->percent;
        $item = $f->item;
        $sameFloor = $percent->minPercent === null || ($state->minPrice !== null && abs($percent->minAmount(2) - $state->minPrice) <= self::SAME_AMOUNT);
        $sameCeiling = $percent->maxPercent === null || ($state->maxPrice !== null && abs($percent->maxAmount(2) - $state->maxPrice) <= self::SAME_AMOUNT);
        $sameStay = $item->minStay === null || $state->minStay === $item->minStay;
        if ($sameFloor && $sameCeiling && $sameStay && !$item->setsDays()) {
            $this->log->item($f, true, PlanIssue::NO_EFFECT, 'Everything the item sets is already in force: writing it would change nothing.');
        }
    }

    /**
     * A floor far above the current price: allowed (a floor may require it), but worth a look,
     * outside holidays and unless the order the item cites allows a larger rise.
     */
    private function rise(ItemFacts $f): void
    {
        $current = $f->current();
        $floor = $f->floor();
        if ($current === null || $current <= 0.0 || $floor === null || $f->holiday !== null) {
            return;
        }
        if ($f->authority->allows(OrderRelease::AboveWeeklyRiseLimit) || in_array($f->item->reasonCode, $this->config->riseExemptReasonCodes, true)) {
            return;
        }
        $percent = $this->config->riseWarningPercent;
        if ($floor > $current * (1 + $percent / 100) + self::SAME_AMOUNT) {
            $this->log->item($f, false, PlanIssue::RISE_ABOVE_MAX_STEP, sprintf('The floor of %.2f is more than %s%% above the current price (%.2f).', $floor, $percent, $current), [
                'applied_floor' => $floor,
                'current_price' => $current,
                'max_step_percent' => $percent,
            ]);
        }
    }
}
