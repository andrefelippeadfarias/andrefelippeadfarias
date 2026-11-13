<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid;

/**
 * What the last completed run decided for one room night (its plan items for floor, ceiling and
 * minimum stay folded together): the status, the amounts in force and proposed, the percent of
 * the listing base it would send, and the reason. Every field is null for a night it did not price.
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
    ) {
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
