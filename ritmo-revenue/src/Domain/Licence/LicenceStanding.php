<?php

declare(strict_types=1);

namespace Ritmo\Domain\Licence;

/**
 * Where the site stands with its licence, as evaluated now from the last answers kept.
 *
 * Active and Grace let the properties leave shadow mode and the AI assistant work; Lapsed and
 * Unlicensed keep every property in shadow mode (the engine still runs and shows what it would
 * do) and switch the assistant off.
 */
enum LicenceStanding: string
{
    /** The provider said the licence is valid and nothing has gone wrong since. */
    case Active = 'active';

    /** Something went wrong (expired, revoked, provider not reached) and the grace period runs. */
    case Grace = 'grace';

    /** The grace period is over: shadow mode only, no assistant. */
    case Lapsed = 'lapsed';

    /** No key, a key never confirmed valid, or a test key outside development mode. */
    case Unlicensed = 'unlicensed';

    public function allowsWriting(): bool
    {
        return $this === self::Active || $this === self::Grace;
    }

    public function allowsAi(): bool
    {
        return $this->allowsWriting();
    }
}
