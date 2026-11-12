<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

/**
 * Arrival/departure restriction of an inventory night.
 */
enum Restriction: string
{
    case None = 'none';
    case Blackout = 'blackout';
    case NoCheckin = 'no_checkin';
    case NoCheckout = 'no_checkout';
    case NoCheckinCheckout = 'no_checkin_checkout';

    public function allowsCheckin(): bool
    {
        return $this === self::None || $this === self::NoCheckout;
    }

    public function allowsCheckout(): bool
    {
        return $this === self::None || $this === self::NoCheckin;
    }
}
