<?php

declare(strict_types=1);

namespace Ritmo\Domain\Licence;

use DateTimeImmutable;

/**
 * Where a licence stands at an instant, from the record of the provider's answers and the grace
 * policy. Pure: it never asks the provider.
 *
 * - No key, a key never answered "valid", or a key confirmed only by the test provider outside
 *   development mode: Unlicensed (shadow mode only, no assistant).
 * - Last answer valid and the last attempt answered: Active, until the last valid answer is older
 *   than the grace period (no check for that long counts as a provider out of reach).
 * - Provider out of reach after a valid answer: Grace until the last valid answer plus the grace
 *   period, then Lapsed.
 * - Expired (by the provider's answer, or by the end date passing since the last valid answer):
 *   Grace from the end of the licence, never from before the last valid answer, then Lapsed.
 * - Invalid after a valid answer (revoked, refunded, moved to another site): Grace from the first
 *   such answer, then Lapsed.
 */
final class LicenceEvaluator
{
    public function __construct(private readonly GracePolicy $grace = new GracePolicy())
    {
    }

    public function evaluate(LicenceRecord $record, DateTimeImmutable $now, bool $devMode): LicenceVerdict
    {
        if (!$record->hasKey()) {
            return new LicenceVerdict(LicenceStanding::Unlicensed, LicenceReason::NO_KEY);
        }
        if ($record->providerId === LicenceRecord::TEST_PROVIDER && !$devMode) {
            return new LicenceVerdict(LicenceStanding::Unlicensed, LicenceReason::TEST_PROVIDER, null, $record->expiresAt);
        }
        if ($record->lastValidAt === null) {
            return new LicenceVerdict(LicenceStanding::Unlicensed, match ($record->status) {
                LicenceRecord::EXPIRED => LicenceReason::EXPIRED,
                LicenceRecord::INVALID => LicenceReason::INVALID,
                default => LicenceReason::NOT_CHECKED,
            }, null, $record->expiresAt);
        }

        return match ($record->status) {
            LicenceRecord::VALID => $this->afterValid($record, $record->lastValidAt, $now),
            LicenceRecord::EXPIRED => $this->graceFrom(self::later($record->expiresAt ?? $record->problemSince, $record->lastValidAt), LicenceReason::EXPIRED, $record, $now),
            default => $this->graceFrom(self::later($record->problemSince, $record->lastValidAt), LicenceReason::INVALID, $record, $now),
        };
    }

    private function afterValid(LicenceRecord $record, DateTimeImmutable $lastValidAt, DateTimeImmutable $now): LicenceVerdict
    {
        $expiresAt = $record->expiresAt;
        $staleAt = $this->grace->endsAt($lastValidAt);
        // The provider is the authority at the moment it answers: an end date already past when it
        // said "valid" does not count; one that passed since then does, while the last valid answer
        // is recent (an end date after a whole grace period without a check never brings a lapsed
        // licence back into grace).
        if ($expiresAt !== null && $expiresAt > $lastValidAt && $expiresAt <= $staleAt && $now >= $expiresAt) {
            return $this->graceFrom($expiresAt, LicenceReason::EXPIRED, $record, $now);
        }
        if ($record->lastAttemptStatus === LicenceRecord::UNREACHABLE || $now >= $staleAt) {
            return $this->graceFrom($lastValidAt, LicenceReason::UNREACHABLE, $record, $now);
        }

        return new LicenceVerdict(LicenceStanding::Active, LicenceReason::NONE, null, $expiresAt);
    }

    private function graceFrom(DateTimeImmutable $start, string $reason, LicenceRecord $record, DateTimeImmutable $now): LicenceVerdict
    {
        $ends = $this->grace->endsAt($start);

        return new LicenceVerdict($now < $ends ? LicenceStanding::Grace : LicenceStanding::Lapsed, $reason, $ends, $record->expiresAt);
    }

    private static function later(?DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a !== null && $a > $b ? $a : $b;
    }
}
