<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid;

use Ritmo\Domain\Pricing\RuleInForce;

/**
 * What the last completed run decided for one room night (its plan items for floor, ceiling and
 * minimum stay folded together): the status, the amounts in force and proposed, the percent of
 * the listing base it would send, the price read and the reason. Every field is null for a night
 * it did not price. nowFromListing: the amounts in force are the listing's minimum and maximum
 * (no date rule).
 *
 * The direction of a change is the direction of the price a guest pays: the price read within the
 * floor and ceiling in force, against the same price within the proposed ones (RuleInForce). A
 * floor raised from the listing minimum while the price itself steps down is a step down. When the
 * price stays (or none was read), it is the direction of the floor: the range moves.
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
        public readonly ?float $currentPrice = null,
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
            $this->currentPrice,
        );
    }

    public static function none(): self
    {
        return new self();
    }

    /** The price a guest pays now: the price read within the floor and ceiling in force. */
    public function priceNow(): ?float
    {
        return self::sold($this->currentPrice, $this->floorNow, $this->ceilingNow);
    }

    /** The price a guest pays once the plan is sent: the price read within the proposed floor and ceiling. */
    public function priceAfter(): ?float
    {
        return self::sold($this->currentPrice, $this->floorProposed ?? $this->floorNow, $this->ceilingProposed ?? $this->ceilingNow);
    }

    /**
     * "up", "down" or "same" for the price a guest pays (the floor when no price was read); null
     * when not priced.
     */
    public function direction(): ?string
    {
        if ($this->status !== self::WOULD_WRITE) {
            return null;
        }
        $now = $this->priceNow();
        $next = $this->priceAfter();
        if ($now !== null && $next !== null && abs($next - $now) >= RuleInForce::MARGIN) {
            return $next > $now ? 'up' : 'down';
        }
        $now = $this->floorNow ?? $this->ceilingNow;
        $next = $this->floorProposed ?? $this->ceilingProposed;
        if ($now === null || $next === null) {
            return null;
        }

        return $next > $now ? 'up' : ($next < $now ? 'down' : 'same');
    }

    /**
     * A price read brought within a floor and a ceiling; null without a price read.
     */
    public static function sold(?float $price, ?float $floor, ?float $ceiling): ?float
    {
        return $price === null ? null : RuleInForce::price($price, $floor, $ceiling);
    }

    /** The pace category of the overview: held at the floor counts apart. */
    public function paceCategory(): string
    {
        return $this->heldAtFloor ? 'floor' : ($this->paceStatus ?? 'none');
    }
}
