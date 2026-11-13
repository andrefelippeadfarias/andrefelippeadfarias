<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Hold;

use DateTimeImmutable;
use Ritmo\Domain\Plan\IssueSubject;

/**
 * An entry of the plan held by a cancellation wave: listed in the journal and the report, never
 * written, never in the step ledger.
 */
final class HeldEntry
{
    public const REASON = 'held_by_cancellation_wave';

    /**
     * @param int $index position of the entry in its list of the plan
     */
    public function __construct(
        public readonly IssueSubject $subject,
        public readonly int $index,
        public readonly string $roomCode,
        public readonly ?DateTimeImmutable $night,
        public readonly string $why,
    ) {
    }
}
