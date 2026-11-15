<?php

declare(strict_types=1);

namespace Ritmo\Domain\Licence;

use DateTimeImmutable;

/**
 * When the scheduled check asks the provider again: once a day (the event fires twice a day,
 * so a check is due 20 hours after the last attempt), and 6 hours after an attempt that did not
 * reach the provider. A site without a key is never checked.
 */
final class CheckDue
{
    public const AFTER_ANSWER_SECONDS = 20 * 3600;

    public const AFTER_UNREACHABLE_SECONDS = 6 * 3600;

    private function __construct()
    {
    }

    public static function isDue(LicenceRecord $record, DateTimeImmutable $now): bool
    {
        if (!$record->hasKey()) {
            return false;
        }
        if ($record->lastAttemptAt === null) {
            return true;
        }
        $wait = $record->lastAttemptStatus === LicenceRecord::UNREACHABLE ? self::AFTER_UNREACHABLE_SECONDS : self::AFTER_ANSWER_SECONDS;

        // A last attempt in the future (the clock moved back) makes the check due at once.
        $elapsed = $now->getTimestamp() - $record->lastAttemptAt->getTimestamp();

        return $elapsed < 0 || $elapsed >= $wait;
    }
}
