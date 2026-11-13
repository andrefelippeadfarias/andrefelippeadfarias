<?php

declare(strict_types=1);

namespace Ritmo\Application\Verify;

/**
 * What a confirmed write left in the records.
 */
final class RecordOutcome
{
    /**
     * @param list<int> $changeIds change-log rows
     * @param list<int> $stepIds   step-ledger rows
     */
    public function __construct(
        public readonly array $changeIds,
        public readonly array $stepIds,
    ) {
    }
}
