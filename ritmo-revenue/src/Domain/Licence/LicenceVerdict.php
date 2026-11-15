<?php

declare(strict_types=1);

namespace Ritmo\Domain\Licence;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The evaluation of a licence record at one instant (LicenceEvaluator::evaluate()).
 */
final class LicenceVerdict
{
    /**
     * @param string                 $reason      a LicenceReason code
     * @param DateTimeImmutable|null $graceEndsAt end of the grace period (Grace: in the future; Lapsed: in the past); null otherwise
     * @param DateTimeImmutable|null $expiresAt   end of the licence as last answered
     */
    public function __construct(
        public readonly LicenceStanding $standing,
        public readonly string $reason,
        public readonly ?DateTimeImmutable $graceEndsAt = null,
        public readonly ?DateTimeImmutable $expiresAt = null,
    ) {
        if (!in_array($reason, LicenceReason::ALL, true)) {
            throw new InvalidArgumentException(sprintf('Unknown licence reason "%s".', $reason));
        }
    }

    /**
     * The properties may leave shadow mode (approval, autonomous).
     */
    public function allowsWriting(): bool
    {
        return $this->standing->allowsWriting();
    }

    /**
     * The AI assistant may work.
     */
    public function allowsAi(): bool
    {
        return $this->standing->allowsAi();
    }

    /**
     * Whole days, rounded up, until the grace period ends; null without one, 0 when it is over.
     */
    public function graceDaysLeft(DateTimeImmutable $now): ?int
    {
        if ($this->graceEndsAt === null) {
            return null;
        }
        $seconds = $this->graceEndsAt->getTimestamp() - $now->getTimestamp();

        return $seconds <= 0 ? 0 : intdiv($seconds + 86399, 86400);
    }
}
