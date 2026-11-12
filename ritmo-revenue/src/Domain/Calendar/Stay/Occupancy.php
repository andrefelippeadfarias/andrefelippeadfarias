<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Support\Days;

/**
 * Free and sold-out nights of one room from a single source, so that the PMS audit and the pricing
 * plan decide orphan nights alike.
 *
 * From the PMS inventory (what really sells): a night with units for sale is free, one with none
 * (or overbooked) is sold out, and a night the PMS does not report is neither. Without the
 * inventory, from the pricing calendar: a reported night is sold out when every unit is sold.
 *
 * Reference: calendario_12m.Ocupacao.
 */
final class Occupancy
{
    /**
     * @param array<string, InventoryNight>   $inventory
     * @param array<string, PricingStayNight> $calendar
     */
    private function __construct(
        private readonly string $source,
        private readonly int $units,
        private readonly array $inventory,
        private readonly array $calendar,
        private readonly ?DateTimeImmutable $readAt,
    ) {
    }

    /**
     * @param array<string, InventoryNight> $nights 'YYYY-MM-DD' => night
     * @param DateTimeImmutable|null        $readAt instant of the read
     */
    public static function fromInventory(array $nights, int $units, ?DateTimeImmutable $readAt): self
    {
        return new self(OrphanProof::SOURCE_PMS, $units, $nights, [], $readAt);
    }

    /**
     * @param array<string, PricingStayNight> $nights 'YYYY-MM-DD' => night
     */
    public static function fromPricing(array $nights, int $units): self
    {
        return new self(OrphanProof::SOURCE_PRICING, $units, [], $nights, null);
    }

    public function free(DateTimeImmutable $night): bool
    {
        if ($this->source === OrphanProof::SOURCE_PMS) {
            $available = $this->available($night);

            return $available !== null && $available > 0;
        }
        $row = $this->calendar[Days::key($night)] ?? null;

        return $row !== null && !$row->isSoldOut();
    }

    public function soldOut(DateTimeImmutable $night): bool
    {
        if ($this->source === OrphanProof::SOURCE_PMS) {
            $available = $this->available($night);

            return $available !== null && $available <= 0;
        }

        return ($this->calendar[Days::key($night)] ?? null)?->isSoldOut() ?? false;
    }

    /**
     * The proof of the night with its neighbour (OrphanProof::NEXT or PREVIOUS) as read.
     */
    public function proof(DateTimeImmutable $night, string $neighbour): OrphanProof
    {
        $other = Days::add($night, $neighbour === OrphanProof::NEXT ? 1 : -1);
        if ($this->source === OrphanProof::SOURCE_PMS) {
            return new OrphanProof($this->source, $neighbour, $this->units, $this->available($night), $this->available($other), readAt: $this->readAt);
        }
        [$nightSold, $nightTotal] = $this->soldAndTotal($night);
        [$otherSold, $otherTotal] = $this->soldAndTotal($other);

        return new OrphanProof($this->source, $neighbour, nightSold: $nightSold, nightTotal: $nightTotal, neighbourSold: $otherSold, neighbourTotal: $otherTotal);
    }

    /**
     * The proof of a free closed day whose previous night has passed: with that night's date, and
     * without numbers the source does not hold for it.
     */
    public function proofPreviousPast(DateTimeImmutable $closedDay): OrphanProof
    {
        $previous = Days::add($closedDay, -1);
        $proof = $this->proof($closedDay, OrphanProof::PREVIOUS);
        if ($this->soldOut($previous)) {
            return $proof;
        }
        $missing = $this->source === OrphanProof::SOURCE_PRICING && !isset($this->calendar[Days::key($previous)]);

        return $proof->withPreviousPast($previous, $missing);
    }

    private function available(DateTimeImmutable $night): ?int
    {
        return ($this->inventory[Days::key($night)] ?? null)?->available;
    }

    /**
     * @return array{int, int} a night the calendar does not report counts as nothing sold
     */
    private function soldAndTotal(DateTimeImmutable $night): array
    {
        $row = $this->calendar[Days::key($night)] ?? null;

        return $row === null ? [0, $this->units] : [$row->sold, $row->total];
    }
}
