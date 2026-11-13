<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Resume;

use Ritmo\Application\Write\Journal\OpStatus;

/**
 * What the resume found in the journal of earlier runs: each unsettled row with its new status
 * and the class of each night, and the broken groups to repair first.
 */
final class ResumeReport
{
    /**
     * @param array<int, OpStatus>                              $rows    journal row id => status after the re-read
     * @param array<int, array<string, array<string, string>>>  $nights  journal row id => target => night => NightClass value
     * @param list<BrokenGroup>                                 $broken
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $nights,
        public readonly array $broken,
    ) {
    }

    /**
     * @return list<int> rows found applied
     */
    public function applied(): array
    {
        return array_keys(array_filter($this->rows, static fn (OpStatus $s): bool => $s === OpStatus::Stored));
    }
}
