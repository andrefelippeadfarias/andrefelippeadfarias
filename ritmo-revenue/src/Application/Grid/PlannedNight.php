<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid;

/**
 * What the last completed run decided for one room night (its plan items for floor, ceiling and
 * minimum stay folded together): the status, the amounts in force and proposed, the percent of
 * the listing base it would send, and the reason. Every field is null for a night it did not price.
 * nowFromListing: the amounts in force are the listing's minimum and maximum (no date rule).
 */
final class PlannedNight
{
    public const WOULD_WRITE = 'would_write';
    public const NO_CHANGE = 'no_change';
    public const REJECTED = 'rejected';

    /**
     * @param list<string>          $issues        validator refusal codes
     * @param list<string>          $relationCodes relations that applied
     */
    public function __construct(
        public readonly ?string $status = null,
        public readonly string $reason = '',
        public readonly ?string $paceStatus = null,
        public readonly ?float $floorNow = null,
        public readonly ?float $floorProposed = null,
        public readonly ?int $floorPercent = null,
        public readonly ?float $ceilingNow = null,
        public readonly ?float $ceilingProposed = null,
        public readonly ?int $ceilingPercent = null,
        public readonly ?int $minStayNow = null,
        public readonly ?int $minStayProposed = null,
        public readonly array $issues = [],
        public readonly array $relationCodes = [],
        public readonly ?string $orderId = null,
        public readonly bool $heldAtFloor = false,
        public readonly bool $nowFromListing = false,
    ) {
    }

    /**
     * A night the run priced without a date rule in force has the listing's own minimum and
     * maximum as its floor and ceiling (the pricing system applies them when no rule exists): this
     * fills them in and marks it, so a change reads "listing minimum R$ 380 → R$ 433".
     */
    public function withListing(float $min, float $max): self
    {
        $floorMissing = $this->floorNow === null && $this->floorProposed !== null && $min > 0;
        $ceilingMissing = $this->ceilingNow === null && $this->ceilingProposed !== null && $max > 0;
        if ($this->status === null || (!$floorMissing && !$ceilingMissing)) {
            return $this;
        }

        return new self(
            $this->status,
            $this->reason,
            $this->paceStatus,
            $floorMissing ? $min : $this->floorNow,
            $this->floorProposed,
            $this->floorPercent,
            $ceilingMissing ? $max : $this->ceilingNow,
            $this->ceilingProposed,
            $this->ceilingPercent,
            $this->minStayNow,
            $this->minStayProposed,
            $this->issues,
            $this->relationCodes,
            $this->orderId,
            $this->heldAtFloor,
            true,
        );
    }

    public static function none(): self
    {
        return new self();
    }

    /** "up", "down" or "same" for the floor (the price follows it); null when not priced. */
    public function direction(): ?string
    {
        $now = $this->floorNow ?? $this->ceilingNow;
        $next = $this->floorProposed ?? $this->ceilingProposed;
        if ($this->status !== self::WOULD_WRITE || $now === null || $next === null) {
            return null;
        }

        return $next > $now ? 'up' : ($next < $now ? 'down' : 'same');
    }

    /** The pace category of the overview: held at the floor counts apart. */
    public function paceCategory(): string
    {
        return $this->heldAtFloor ? 'floor' : ($this->paceStatus ?? 'none');
    }
}
