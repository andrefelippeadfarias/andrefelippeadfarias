<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Cadence;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Support\Days;

/**
 * The step an item carries: where the price was, where it goes and what was sold. Only an item
 * that is written and carries a direction becomes a ledger row ({@see StepLedger::record()}).
 *
 * Reference: the "pace" block of a plan item (pace.py item_do_plano and _plano).
 */
final class StepProposal
{
    public readonly DateTimeImmutable $night;

    /**
     * @param string             $sourceStatus pace status of the step, or StepRecord::LADDER for a ladder step
     * @param StepDirection|null $direction    null when the change is inside the tolerance (not a step)
     * @param int                $targetSent   target of the engine in the sent plan, rounded
     */
    public function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $night,
        public readonly string $sourceStatus,
        public readonly ?StepDirection $direction,
        public readonly float $priceFrom,
        public readonly float $priceTo,
        public readonly int $targetSent,
        public readonly ?int $sold,
        public readonly SoldBase $soldBase,
        public readonly ?int $soldTotal,
    ) {
        if (!Identifier::isCode($roomCode)) {
            throw new InvalidArgumentException(sprintf('Invalid room type code "%s".', $roomCode));
        }
        $this->night = Days::normalize($night);
    }

    /**
     * A step as toArray() wrote it.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException on a malformed step
     */
    public static function fromArray(array $data): self
    {
        $direction = $data['direction'] ?? null;
        $sold = $data['sold'] ?? null;
        $total = $data['sold_total'] ?? null;
        if (!is_string($data['room'] ?? null) || !is_string($data['night'] ?? null) || !Days::isValid($data['night'])) {
            throw new InvalidArgumentException('A step needs its room and night.');
        }

        return new self(
            $data['room'],
            Days::date($data['night']),
            (string) ($data['source_status'] ?? ''),
            is_string($direction) ? StepDirection::from($direction) : null,
            (float) ($data['price_from'] ?? 0.0),
            (float) ($data['price_to'] ?? 0.0),
            (int) ($data['target_sent'] ?? 0),
            is_int($sold) ? $sold : null,
            SoldBase::from((string) ($data['sold_base'] ?? SoldBase::Room->value)),
            is_int($total) ? $total : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'room' => $this->roomCode,
            'night' => Days::key($this->night),
            'source_status' => $this->sourceStatus,
            'direction' => $this->direction?->value,
            'price_from' => $this->priceFrom,
            'price_to' => $this->priceTo,
            'target_sent' => $this->targetSent,
            'sold' => $this->sold,
            'sold_base' => $this->soldBase->value,
            'sold_total' => $this->soldTotal,
        ];
    }
}
