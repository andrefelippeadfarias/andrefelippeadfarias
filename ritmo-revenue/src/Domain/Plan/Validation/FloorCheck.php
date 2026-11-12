<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

use Ritmo\Domain\Config\FloorRule;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Orders\OrderRelease;
use Ritmo\Domain\Plan\PlanConfig;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Plan\Port\StrategyGuard;
use Ritmo\Domain\Pricing\Floors;

/**
 * The floors of the night against the lowest price an item allows (its floor as applied, or its
 * ceiling when it sets no floor), each family on its own:
 *
 * - the safety limit (never released; when it fails, it is the only floor reported);
 * - the listing minimum (floors only): an order that allows it, or the last-minute discount rule;
 * - on a night of a configured period (floors only), the period's own floor and, when the night is
 *   priced as a holiday, the general holiday floor (never released), both in their price plan;
 * - the owner floors of the night type, unless a strategy replaces them that night: an order that
 *   allows it (a warning), or down to the last-minute market floor of the strategy the item cites;
 * - the quality floor: an order that allows it, or market evidence written in the item (warnings).
 *
 * An owner floor or the quality floor may stand one currency unit under the amount (a step is a
 * whole unit), and so may the period's floor in its own plan; the listing minimum and the general
 * holiday floor may not.
 *
 * Reference: validar_plano.Validador.validar_data (limite de segurança, Regra I, feriado),
 * padrao_qualidade and piso_vitrine_semana.
 */
final class FloorCheck implements ItemCheck
{
    private const MARGIN = 1.0;

    private readonly Floors $floors;

    public function __construct(
        private readonly RuleSet $rules,
        private readonly PriceFactor $factors,
        private readonly PlanConfig $config,
        private readonly StrategyGuard $strategies,
        private readonly LastMinuteCheck $lastMinute,
        private readonly IssueLog $log,
    ) {
        $this->floors = new Floors($rules, $factors);
    }

    public function check(ItemFacts $f): void
    {
        $lowest = $f->lowest();
        if ($lowest === null) {
            return;
        }
        $safety = $this->floors->safetyLimit($f->room);
        $raw = $f->item->minPrice;
        if ($lowest < $safety || ($raw !== null && $raw < $safety)) {
            $amount = $raw !== null && $raw < $lowest ? $raw : $lowest;
            $this->log->item($f, true, PlanIssue::BELOW_SAFETY_LIMIT, sprintf('%.2f is under the safety limit of %.2f.', $amount, $safety), ['amount' => $amount, 'safety_limit' => $safety]);

            return;
        }
        if ($f->floor() !== null) {
            $this->listingMinimum($f, $lowest);
            $this->holiday($f, $lowest);
        }
        $this->ownerFloors($f, $lowest);
        $this->quality($f, $lowest);
    }

    private function listingMinimum(ItemFacts $f, float $floor): void
    {
        if ($floor >= $f->room->min || $f->authority->allows(OrderRelease::BelowStandardFloor)) {
            return;
        }
        if ($f->holiday === null && $this->config->lastMinute->coversRoom($f->room->code) && $f->daysOut <= $this->config->lastMinute->maxDaysOut) {
            $this->lastMinute->check($f, $floor);

            return;
        }
        $this->log->item($f, true, PlanIssue::BELOW_LISTING_MIN, sprintf('The floor of %.2f is under the listing minimum of %.2f.', $floor, $f->room->min), [
            'amount' => $floor,
            'listing_min' => $f->room->min,
            'order_id' => $f->authority->id(),
        ]);
    }

    private function holiday(ItemFacts $f, float $floor): void
    {
        if ($f->holiday === null) {
            return;
        }
        $rule = $this->rules->holidayFloor();
        $value = $this->factors->fromSent($floor, $rule->pricePlan, $f->room, $f->nightType);
        if ($f->nightType === NightType::Holiday && $rule->amount > 0 && $value < $rule->amount) {
            $this->log->item($f, true, PlanIssue::BELOW_HOLIDAY_SAFETY_FLOOR, sprintf('The floor of %.2f is %.2f in the %s plan, under the holiday safety floor of %.2f.', $floor, $value, $rule->pricePlan->value, $rule->amount), [
                'amount' => $floor,
                'value_in_plan' => $value,
                'holiday_floor' => $rule->amount,
            ]);
        }
        $own = $f->holiday->floor;
        if ($own !== null && $own > 0 && $value < $own - self::MARGIN && !$f->authority->allows(OrderRelease::BelowStandardFloor)) {
            $this->log->item($f, true, PlanIssue::BELOW_HOLIDAY_FLOOR, sprintf('The floor of %.2f is %.2f in the %s plan, under the floor of %s, %.2f.', $floor, $value, $rule->pricePlan->value, $f->holiday->name, $own), [
                'amount' => $floor,
                'value_in_plan' => $value,
                'period_floor' => $own,
            ]);
        }
    }

    private function ownerFloors(ItemFacts $f, float $lowest): void
    {
        if ($this->strategies->replacesOwnerFloors($f->room->code, $f->item->date)) {
            return;
        }
        foreach ($this->rules->ownerFloors() as $rule) {
            if (!$rule->appliesTo($f->nightType, $f->room)) {
                continue;
            }
            $amount = $this->toSent($rule, $f);
            if ($lowest < $amount - self::MARGIN) {
                $this->belowOwnerFloor($f, $lowest, $amount);
            }
        }
    }

    private function belowOwnerFloor(ItemFacts $f, float $lowest, float $amount): void
    {
        $params = ['amount' => $lowest, 'owner_floor' => $amount, 'order_id' => $f->authority->id()];
        if ($f->authority->backsStrategy) {
            $market = $this->strategies->marketFloor((string) $f->authority->id(), $f->room->code, $f->item->date);
            $slack = 0.01 * $f->room->base + self::MARGIN;
            if ($market === null || $lowest < $market - $slack) {
                $this->log->item($f, true, PlanIssue::BELOW_MARKET_FLOOR, sprintf('%.2f is under the owner floor of %.2f and the strategy of order %s sets no market floor that low tonight.', $lowest, $amount, $f->authority->id()), $params + ['market_floor' => $market]);
            } else {
                $this->log->item($f, false, PlanIssue::BELOW_FLOOR_BY_MARKET_FLOOR, sprintf('%.2f is under the owner floor of %.2f, at the last-minute market floor of %.2f.', $lowest, $amount, $market), $params + ['market_floor' => $market]);
            }

            return;
        }
        if ($f->authority->allows(OrderRelease::BelowStandardFloor)) {
            $this->log->item($f, false, PlanIssue::BELOW_FLOOR_BY_ORDER, sprintf('%.2f is under the owner floor of %.2f by order %s.', $lowest, $amount, $f->authority->id()), $params);

            return;
        }
        $this->log->item($f, true, PlanIssue::BELOW_OWNER_FLOOR, sprintf('%.2f is under the owner floor of the night, %.2f.', $lowest, $amount), $params);
    }

    private function quality(ItemFacts $f, float $lowest): void
    {
        $rule = $this->rules->qualityFloor();
        if (!$rule->appliesTo($f->nightType, $f->room)) {
            return;
        }
        $amount = $this->toSent($rule, $f);
        if ($lowest >= $amount - self::MARGIN) {
            return;
        }
        $params = ['amount' => $lowest, 'quality_floor' => $amount];
        if ($f->authority->allows(OrderRelease::BelowStandardFloor)) {
            $this->log->item($f, false, PlanIssue::BELOW_QUALITY_BY_ORDER, sprintf('%.2f is under the quality floor of %.2f by order %s.', $lowest, $amount, $f->authority->id()), $params);
        } elseif ($this->hasMarketEvidence($f)) {
            $this->log->item($f, false, PlanIssue::BELOW_QUALITY_BY_MARKET, sprintf('%.2f is under the quality floor of %.2f by market evidence: %s', $lowest, $amount, trim((string) $f->item->marketEvidence)), $params);
        } else {
            $this->log->item($f, true, PlanIssue::BELOW_QUALITY_FLOOR, sprintf('%.2f is under the quality floor of %.2f; going under it needs an owner order or market evidence with a number in the item.', $lowest, $amount), $params);
        }
    }

    /**
     * Market evidence long enough and with a number in it (a median, an occupancy, a pace).
     */
    private function hasMarketEvidence(ItemFacts $f): bool
    {
        $text = trim((string) $f->item->marketEvidence);

        return mb_strlen($text) >= $this->config->marketEvidenceMinLength && preg_match('/\d/', $text) === 1;
    }

    private function toSent(FloorRule $rule, ItemFacts $f): float
    {
        return $this->factors->toSent($rule->amount, $rule->pricePlan, $f->room, $f->nightType);
    }
}
