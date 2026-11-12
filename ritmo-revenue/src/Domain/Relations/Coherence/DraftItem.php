<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * A date item of the plan being built: the floor and ceiling a room-night will get. The engine,
 * the ladder and the relation passes change it in place until the plan is validated.
 */
final class DraftItem
{
    /** @var list<Reason> */
    private array $reasons = [];

    /**
     * @param float $priceBefore the calendar price of the room-night before the plan
     * @param bool  $engineStep  the item is a pace step of the engine
     * @param bool  $strategyItem the item was written by the sales ladder
     * @param bool  $protection  the item protects a sold-out night
     */
    public function __construct(
        public readonly string $room,
        public readonly DateTimeImmutable $night,
        public readonly float $priceBefore,
        private ?float $floor = null,
        private ?float $ceiling = null,
        private ?string $orderRef = null,
        public readonly bool $engineStep = false,
        public readonly bool $strategyItem = false,
        public readonly bool $protection = false,
    ) {
        foreach ([$floor, $ceiling] as $amount) {
            if ($amount !== null && (!is_finite($amount) || $amount < 0)) {
                throw new InvalidArgumentException('Floors and ceilings cannot be negative.');
            }
        }
    }

    public function key(): string
    {
        return RoomNight::key($this->room, $this->night);
    }

    public function floor(): ?float
    {
        return $this->floor;
    }

    public function ceiling(): ?float
    {
        return $this->ceiling;
    }

    public function orderRef(): ?string
    {
        return $this->orderRef;
    }

    public function setFloor(float $floor): void
    {
        $this->floor = $floor;
    }

    public function setCeiling(float $ceiling): void
    {
        $this->ceiling = $ceiling;
    }

    /** Floor and ceiling at the same value. */
    public function pin(float $price): void
    {
        $this->floor = $price;
        $this->ceiling = $price;
    }

    public function citeOrder(?string $orderRef): void
    {
        $this->orderRef = $orderRef;
    }

    /**
     * @return list<Reason>
     */
    public function reasons(): array
    {
        return $this->reasons;
    }

    /** Adds the reason at the end, unless the same relation already gave one. */
    public function addReason(Reason $reason): void
    {
        if (!$this->hasReason($reason)) {
            $this->reasons[] = $reason;
        }
    }

    /** Puts the reason first (the reason that decides the item). */
    public function putReasonFirst(Reason $reason): void
    {
        array_unshift($this->reasons, $reason);
    }

    public function hasReason(Reason $reason): bool
    {
        foreach ($this->reasons as $known) {
            if ($known->sameAs($reason)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{room: string, night: string, floor: float|null, ceiling: float|null, order_ref: string|null, price_before: float}
     */
    public function toArray(): array
    {
        return [
            'room' => $this->room,
            'night' => Days::key($this->night),
            'floor' => $this->floor,
            'ceiling' => $this->ceiling,
            'order_ref' => $this->orderRef,
            'price_before' => $this->priceBefore,
        ];
    }
}
