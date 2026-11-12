<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

/**
 * The ladder price of one room on one night, in the sent plan, with the figures behind it.
 * Immutable: the relations of the night return a changed copy.
 */
final class LadderRoomQuote
{
    public const RELATION_BETWEEN = 'RELATION_BETWEEN';
    public const RELATION_PREMIUM_OVER_MAX = 'RELATION_PREMIUM_OVER_MAX';

    /**
     * @param float                         $sent      ladder price, sent plan (never below the hard floor)
     * @param float                         $public    the same in the public plan of the night
     * @param array{0: ?float, 1: ?float}|null $limits the band the live price, floor and ceiling must respect for
     *                                                 the room to be "in order" with its relations
     * @param float|null                    $baseSent  the price before the relations of the night moved it
     * @param list<string>                  $relationReasons relations that moved the price
     */
    public function __construct(
        public readonly string $roomCode,
        public readonly string $nightType,
        public readonly float $sent,
        public readonly float $public,
        public readonly ?float $opening,
        public readonly int $steps,
        public readonly bool $dropped,
        public readonly int $sold,
        public readonly int $total,
        public readonly float $hardFloor,
        public readonly string $hardFloorLabel,
        public readonly float $factor,
        public readonly bool $derived = false,
        public readonly ?array $limits = null,
        public readonly ?float $baseSent = null,
        public readonly array $relationReasons = [],
    ) {
    }

    /**
     * The quote moved by the relations of the night.
     *
     * @param array{0: ?float, 1: ?float}|null $limits
     * @param list<string>                     $reasons
     */
    public function withRelations(float $sent, ?array $limits, float $baseSent, array $reasons): self
    {
        return new self(
            $this->roomCode,
            $this->nightType,
            $sent,
            $sent * $this->factor,
            $this->opening,
            $this->steps,
            $this->dropped,
            $this->sold,
            $this->total,
            $this->hardFloor,
            $this->hardFloorLabel,
            $this->factor,
            $this->derived,
            $limits,
            $baseSent,
            $reasons,
        );
    }
}
