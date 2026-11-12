<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market;

use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Model\RoomType;

/**
 * Converts an amount between the price plans of a room: what the pricing system sends
 * ("sent"), what a guest sees ("public") and the lowest price after the deepest standing
 * discount ("discounted").
 *
 * Each plan has a measured factor in the rule set: plan price = sent price x factor.
 * Reference: pace.fator_publico and the "vitrine" and "real" factors of pace.pisos_enviados.
 */
final class PriceFactor
{
    public function __construct(private readonly RuleSet $rules)
    {
    }

    /**
     * The factor of a plan for a room on a night type: 1.0 for the sent plan, otherwise the
     * room's own exception, then the table of its property, then the "*" table. The rule set
     * only holds factors above zero.
     */
    public function factor(RoomType $room, PricePlan $plan, NightType $type): float
    {
        return $this->rules->priceFactor($room->propertyId, $plan, $type, $room->code);
    }

    /**
     * An amount of a plan expressed in the sent plan: amount / factor.
     */
    public function toSent(float $amount, PricePlan $from, RoomType $room, NightType $type): float
    {
        return $from === PricePlan::Sent ? $amount : $amount / $this->factor($room, $from, $type);
    }

    /**
     * A sent amount expressed in another plan: amount x factor.
     */
    public function fromSent(float $amount, PricePlan $to, RoomType $room, NightType $type): float
    {
        return $to === PricePlan::Sent ? $amount : $amount * $this->factor($room, $to, $type);
    }

    /**
     * An amount of one plan expressed in another, through the sent plan.
     */
    public function convert(float $amount, PricePlan $from, PricePlan $to, RoomType $room, NightType $type): float
    {
        if ($from === $to) {
            return $amount;
        }

        return $this->fromSent($this->toSent($amount, $from, $room, $type), $to, $room, $type);
    }
}
