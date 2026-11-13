<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Quota;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One recalculation attempt of a listing: when (UTC, whole seconds), how it ended, the error in
 * one line of at most ERROR_LENGTH characters (never a credential: the caller passes a message
 * of the contracts' exceptions) and the response id when the provider gave one.
 */
final class RecalcAttempt
{
    public const ERROR_LENGTH = 300;

    public readonly DateTimeImmutable $at;

    public readonly ?string $error;

    /**
     * @throws InvalidArgumentException on an empty listing
     */
    public function __construct(
        public readonly string $listing,
        DateTimeImmutable $at,
        public readonly AttemptOutcome $outcome,
        ?string $error = null,
        public readonly ?string $responseId = null,
        public readonly ?int $id = null,
    ) {
        if ($listing === '') {
            throw new InvalidArgumentException('An attempt names its listing.');
        }
        $utc = $at->setTimezone(new DateTimeZone('UTC'));
        $this->at = $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), (int) $utc->format('s'));
        $this->error = self::squeeze($error);
    }

    /**
     * The outcome that counts now: a pending attempt older than the timeout is a failure.
     */
    public function effectiveOutcome(DateTimeImmutable $now, int $pendingTimeoutSeconds): AttemptOutcome
    {
        if ($this->outcome === AttemptOutcome::Pending && $now->getTimestamp() - $this->at->getTimestamp() > $pendingTimeoutSeconds) {
            return AttemptOutcome::Failure;
        }

        return $this->outcome;
    }

    private static function squeeze(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }
        $line = trim((string) preg_replace('/\s+/u', ' ', $error));

        return $line === '' ? null : mb_substr($line, 0, self::ERROR_LENGTH, 'UTF-8');
    }
}
