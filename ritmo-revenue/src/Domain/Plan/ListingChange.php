<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use InvalidArgumentException;
use Ritmo\Domain\Model\Identifier;

/**
 * A change to the listing itself (its base, minimum or maximum price), as opposed to a date rule.
 * Prices are corrected by percentages on dates; the listing changes only by owner order and within
 * the weekly limit.
 *
 * Reference: the "anuncio" items of validar_plano.py.
 */
final class ListingChange
{
    /**
     * @param float       $value   the new amount, sent plan
     * @param string|null $orderId the owner order that asks for it
     *
     * @throws InvalidArgumentException on an invalid room code, amount or order id
     */
    public function __construct(
        public readonly string $roomCode,
        public readonly ListingField $field,
        public readonly float $value,
        public readonly string $reasonCode = '',
        public readonly string $reasonText = '',
        public readonly ?string $orderId = null,
    ) {
        if (!Identifier::isCode($roomCode)) {
            throw new InvalidArgumentException(sprintf('Invalid room type code "%s".', $roomCode));
        }
        if (!is_finite($value) || $value <= 0) {
            throw new InvalidArgumentException('A listing amount must be greater than zero.');
        }
        if ($orderId === '') {
            throw new InvalidArgumentException('An order id cannot be empty; use null for no order.');
        }
    }

    /**
     * @return array<string, float|string|null>
     */
    public function toArray(): array
    {
        return [
            'room' => $this->roomCode,
            'field' => $this->field->value,
            'value' => $this->value,
            'reason_code' => $this->reasonCode,
            'reason_text' => $this->reasonText,
            'order_id' => $this->orderId,
        ];
    }
}
