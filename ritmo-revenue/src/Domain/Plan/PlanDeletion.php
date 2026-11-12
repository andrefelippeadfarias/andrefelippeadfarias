<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Support\Days;

/**
 * A date rule of the pricing system to delete before the items are written. The pricing system
 * merges the fields of a date rule, so freeing a ceiling means deleting the rule and writing it
 * again: the deletion goes first, and its night usually has an item that writes it again.
 *
 * Reference: the "apagar" items of validar_plano.py (pace.ajustar_tetos, the delete-first plan).
 */
final class PlanDeletion
{
    public readonly DateTimeImmutable $date;

    /**
     * @throws InvalidArgumentException on an invalid room code
     */
    public function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $date,
        public readonly string $reasonCode = '',
        public readonly string $reasonText = '',
    ) {
        if (!Identifier::isCode($roomCode)) {
            throw new InvalidArgumentException(sprintf('Invalid room type code "%s".', $roomCode));
        }
        $this->date = Days::normalize($date);
    }

    public function key(): string
    {
        return PlanItem::keyOf($this->roomCode, $this->date);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['room' => $this->roomCode, 'date' => Days::key($this->date), 'reason_code' => $this->reasonCode, 'reason_text' => $this->reasonText];
    }
}
