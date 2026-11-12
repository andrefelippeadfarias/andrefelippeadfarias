<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

use DateTimeImmutable;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Support\Days;

/**
 * What the pace engine decided for one room night, with the numbers behind the decision.
 *
 * Amounts are in the sent plan unless the name says "public". Fields the engine did not reach
 * (a skipped night has no pace reading or target) are null.
 */
final class PaceDecision
{
    /**
     * @param string             $reasonCode        the RuleCode of the outcome: "pace_step" for a step, otherwise why nothing is proposed
     * @param float              $currentPrice      price of the night before the decision
     * @param PaceReading|null   $reading           where the night is against its curve
     * @param float|null         $targetPercentile  market percentile the night aims at
     * @param float|null         $targetPublic      that percentile as a public price
     * @param float|null         $targetSent        the target in the sent plan, after "ahead never lowers"
     * @param float|null         $publicFactor      public price = sent price x this factor
     * @param float|null         $currentPercentile market percentile of the current price
     * @param float|null         $proposedPrice     the price the engine arrived at, in whole currency units
     * @param StepDirection|null $direction         direction of the change; null when it is within the tolerance. For a
     *                                              step it is the direction of what is written, which the ledger records
     * @param float|null         $minPrice          floor to write (steps only): the proposed price, lowered when the
     *                                              listing maximum requires it
     * @param float|null         $maxPrice          ceiling to write (steps only), null to leave the ceiling as it is
     * @param HardFloor|null     $floor             hard floor of the night
     * @param bool               $heldAtFloor       true when the step stopped at the hard floor
     * @param bool               $weakAnchor        true when the anchor was small or old and the step was halved
     * @param float|null         $stepPrice         the price after the step (or the order's free step or ramp), before
     *                                              the relation cap, the floor, the listing top and tonight's rule
     * @param list<array{code: string, params: array<string, scalar|null>}> $trace the rules applied, in order
     */
    public function __construct(
        public readonly string $roomCode,
        public readonly DateTimeImmutable $night,
        public readonly int $daysOut,
        public readonly NightType $nightType,
        public readonly DecisionKind $kind,
        public readonly string $reasonCode,
        public readonly float $currentPrice,
        public readonly array $trace,
        public readonly ?MarketAnchor $anchor = null,
        public readonly ?PaceReading $reading = null,
        public readonly ?float $targetPercentile = null,
        public readonly ?float $targetPublic = null,
        public readonly ?float $targetSent = null,
        public readonly ?float $publicFactor = null,
        public readonly ?float $currentPercentile = null,
        public readonly ?float $proposedPrice = null,
        public readonly ?StepDirection $direction = null,
        public readonly ?float $minPrice = null,
        public readonly ?float $maxPrice = null,
        public readonly ?HardFloor $floor = null,
        public readonly bool $heldAtFloor = false,
        public readonly bool $weakAnchor = false,
        public readonly ?float $stepPrice = null,
    ) {
    }

    public function isStep(): bool
    {
        return $this->kind === DecisionKind::Step;
    }

    /**
     * True when the trace holds the rule.
     */
    public function applied(string $ruleCode): bool
    {
        return $this->traceParams($ruleCode) !== null;
    }

    /**
     * The parameters of the first trace entry of a rule, or null when the rule was not applied.
     *
     * @return array<string, scalar|null>|null
     */
    public function traceParams(string $ruleCode): ?array
    {
        foreach ($this->trace as $entry) {
            if ($entry['code'] === $ruleCode) {
                return $entry['params'];
            }
        }

        return null;
    }

    /**
     * The decision as plain data, for storage and for the admin.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'room' => $this->roomCode,
            'night' => Days::key($this->night),
            'days_out' => $this->daysOut,
            'night_type' => $this->nightType->value,
            'kind' => $this->kind->value,
            'reason_code' => $this->reasonCode,
            'current_price' => $this->currentPrice,
            'pace_status' => $this->reading?->status->value,
            'sold_percent' => $this->reading?->soldPercent,
            'expected_percent' => $this->reading?->expectedPercent,
            'target_percentile' => $this->targetPercentile,
            'target_public' => $this->targetPublic,
            'target_sent' => $this->targetSent,
            'public_factor' => $this->publicFactor,
            'current_percentile' => $this->currentPercentile,
            'proposed_price' => $this->proposedPrice,
            'direction' => $this->direction?->value,
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'floor' => $this->floor?->amount,
            'floor_label' => $this->floor?->label,
            'held_at_floor' => $this->heldAtFloor,
            'anchor_origin' => $this->anchor?->origin->value,
            'weak_anchor' => $this->weakAnchor,
            'rule_trace' => $this->trace,
        ];
    }
}
