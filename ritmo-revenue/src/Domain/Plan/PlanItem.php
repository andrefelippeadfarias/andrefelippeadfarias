<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Pricing\PaceDecision;
use Ritmo\Domain\Support\Days;

/**
 * One change a plan proposes for a room night: a floor, a ceiling and/or a minimum stay, with
 * the rule that asked for it and what was in force before.
 *
 * Amounts are in the sent plan, in currency; they become percentages over the listing base only
 * when the payload is built (PercentRounding). There is no price override: the pricing system keeps
 * floating between the floor and the ceiling. The constructor checks the shape of the item, not
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
     * @param string|null           $checkInMask  arrival days to set, seven 0/1 characters from Monday; null leaves them
     * @param string|null           $checkOutMask departure days to set, the same way
     * @param string|null           $orderId      the owner order that authorises what the safety rules would refuse
     * @param OrphanClaim|null      $orphan       the night is an orphan and takes a shorter stay, with the occupancy read
     * @param string|null           $marketEvidence the market data that justifies going under the quality floor
     * @param bool                  $keepReason   leave the reason of the date rule in force (an item that only sets
     *                                            the stay or the arrival and departure days)
     * @param string                $reasonText   the reason as written to the pricing system (empty: the reason code)
     * @param bool                  $readsOccupancy the decision read units sold or free: it needs a fresh calendar
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
        public readonly ?string $checkInMask = null,
        public readonly ?string $checkOutMask = null,
        public readonly ?string $orderId = null,
        public readonly ?OrphanClaim $orphan = null,
        public readonly ?string $marketEvidence = null,
        public readonly bool $keepReason = false,
        public readonly string $reasonText = '',
        public readonly bool $readsOccupancy = false,
    ) {
        if (!Identifier::isCode($roomCode)) {
            throw new InvalidArgumentException(sprintf('Invalid room type code "%s".', $roomCode));
        }
        if ($minPrice === null && $maxPrice === null && $minStay === null && !$this->setsDays()) {
            throw new InvalidArgumentException('A plan item must set a floor, a ceiling, a minimum stay or the arrival and departure days.');
        }
        foreach ([$checkInMask, $checkOutMask] as $mask) {
            if ($mask !== null && !DateOverride::isValidMask($mask)) {
                throw new InvalidArgumentException('A weekday mask has seven characters, each 0 or 1, Monday to Sunday.');
            }
        }
        if ($orderId === '') {
            throw new InvalidArgumentException('An order id cannot be empty; use null for no order.');
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
     * True when the item sets the arrival or departure days.
     */
    public function setsDays(): bool
    {
        return $this->checkInMask !== null || $this->checkOutMask !== null;
    }

    /**
     * True when the item sets a floor or a ceiling.
     */
    public function setsPrice(): bool
    {
        return $this->minPrice !== null || $this->maxPrice !== null;
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
            'check_in' => $this->checkInMask,
            'check_out' => $this->checkOutMask,
            'order_id' => $this->orderId,
            'orphan' => $this->orphan?->toArray(),
            'market_evidence' => $this->marketEvidence,
            'keep_reason' => $this->keepReason,
            'reason_text' => $this->reasonText,
            'reads_occupancy' => $this->readsOccupancy,
        ];
    }
}
