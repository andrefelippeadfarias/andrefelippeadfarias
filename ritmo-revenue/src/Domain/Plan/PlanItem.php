<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Pricing\PaceDecision;
use Ritmo\Domain\Support\Days;

/**
 * One change a plan proposes for a room night: a floor, a ceiling and/or a minimum stay, with
 * the rule that asked for it and what was in force before.
 *
 * Amounts are in the sent plan, in currency; they become percentages over the listing base only
 * when the payload is built (PercentRounding). The constructor checks the shape of the item, not
 * the rules: an item under the hard floor can be built, and the Validator rejects it.
 */
final class PlanItem
{
    public readonly DateTimeImmutable $date;

    /**
     * @param string                $roomCode     code of the room type
     * @param DateTimeImmutable     $date         the night
     * @param float|null            $minPrice     floor to set, or null to leave the floor as it is
     * @param float|null            $maxPrice     ceiling to set, or null to leave the ceiling as it is
     * @param int|null              $minStay      minimum stay to set, or null to leave it as it is
     * @param string                $reasonCode   code of the rule that produced the item ("pace_step")
     * @param array<string, mixed>  $reasonParams parameters of that rule, for the record
     * @param NightState|null       $before       what is in force for the night
     *
     * @throws InvalidArgumentException on an invalid room code, an item that sets nothing, or an invalid value
     */
    public function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $date,
        public readonly ?float $minPrice = null,
        public readonly ?float $maxPrice = null,
        public readonly ?int $minStay = null,
        public readonly string $reasonCode = '',
        public readonly array $reasonParams = [],
        public readonly ?NightState $before = null,
    ) {
        if (!Identifier::isCode($roomCode)) {
            throw new InvalidArgumentException(sprintf('Invalid room type code "%s".', $roomCode));
        }
        if ($minPrice === null && $maxPrice === null && $minStay === null) {
            throw new InvalidArgumentException('A plan item must set a floor, a ceiling or a minimum stay.');
        }
        foreach ([$minPrice, $maxPrice] as $amount) {
            if ($amount !== null && (!is_finite($amount) || $amount <= 0)) {
                throw new InvalidArgumentException('Floors and ceilings must be greater than zero.');
            }
        }
        if ($minStay !== null && $minStay < 1) {
            throw new InvalidArgumentException('The minimum stay must be at least one night.');
        }
        $this->date = Days::normalize($date);
    }

    /**
     * The item that writes a step of the pace engine.
     *
     * @throws InvalidArgumentException when the decision is not a step
     */
    public static function fromDecision(PaceDecision $decision, ?NightState $before = null): self
    {
        if (!$decision->isStep()) {
            throw new InvalidArgumentException('Only a step decision becomes a plan item.');
        }

        return new self(
            $decision->roomCode,
            $decision->night,
            $decision->minPrice,
            $decision->maxPrice,
            null,
            $decision->reasonCode,
            ['direction' => $decision->direction?->value, 'rule_trace' => $decision->trace],
            $before ?? new NightState($decision->currentPrice),
        );
    }

    /**
     * Identifies the room night: "CODE|YYYY-MM-DD".
     */
    public function key(): string
    {
        return self::keyOf($this->roomCode, $this->date);
    }

    /**
     * The key of a room night, as key() gives it.
     */
    public static function keyOf(string $roomCode, DateTimeInterface $night): string
    {
        return $roomCode . '|' . Days::key($night);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'room' => $this->roomCode,
            'date' => Days::key($this->date),
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'min_stay' => $this->minStay,
            'reason_code' => $this->reasonCode,
            'reason_params' => $this->reasonParams,
            'before' => $this->before?->toArray(),
        ];
    }
}
