<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Protection;

/**
 * What the sold-out protection decided for a room night: an item, or why there is none.
 */
final class ProtectionOutcome
{
    public const DISABLED = 'PROTECTION_DISABLED';
    public const NO_ANCHOR = 'SOLD_OUT_NO_ANCHOR';
    public const UNKNOWN_LIVE_FLOOR = 'PROTECTION_UNKNOWN_LIVE_FLOOR';
    public const ALREADY_IN_PLACE = 'PROTECTION_ALREADY_IN_PLACE';
    public const ITEM = 'SOLD_OUT_PROTECTION_ITEM';

    private function __construct(
        public readonly string $code,
        public readonly ?ProtectionItem $item,
    ) {
    }

    public static function item(ProtectionItem $item): self
    {
        return new self(self::ITEM, $item);
    }

    public static function none(string $code): self
    {
        return new self($code, null);
    }
}
