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
     * @param list<array<string, mixed>>                        $journal the journal rows read (WriteOpRepository::unsettled())
     * @param list<int>                                         $kept    rows left unresolved: those of the broken groups
     * @param array<int, array<string, array<string, mixed>>>   $observed journal row id => target => night => the state read now
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $nights,
        public readonly array $broken,
        public readonly array $journal = [],
        public readonly array $kept = [],
        public readonly array $observed = [],
    ) {
    }

    /**
     * The same report with the rows left unresolved.
     *
     * @param list<int> $kept
     */
    public function keeping(array $kept): self
    {
        return new self($this->rows, $this->nights, $this->broken, $this->journal, $kept, $this->observed);
    }

    /**
     * The journal rows whose unknown outcome the re-read found applied, with what was read: the
     * write of a request that died before it could confirm and record it.
     *
     * @return list<array<string, mixed>> rows as WriteOpRepository gives them, status "stored" and the state read as observed
     */
    public function recovered(): array
    {
        $out = [];
        foreach ($this->journal as $row) {
            if (!in_array($row['id'], $this->kept, true) && ($this->rows[$row['id']] ?? null) === OpStatus::Stored && $row['status'] !== OpStatus::Stored->value) {
                $out[] = ['status' => OpStatus::Stored->value, 'observed_state' => $this->observed[$row['id']] ?? []] + $row;
            }
        }

        return $out;
    }

    /**
     * The journal rows whose nights hold a write of the plugin that no run has observed yet: the
     * rows left open (a broken group) and the rows whose unknown outcome the re-read found applied.
     * A difference on those nights is the plugin's own, never someone else's.
     *
     * @return list<array<string, mixed>>
     */
    public function ownUnobserved(): array
    {
        return array_values(array_filter($this->journal, fn (array $row): bool => in_array($row['id'], $this->kept, true)
            || (($this->rows[$row['id']] ?? null) === OpStatus::Stored && $row['status'] !== OpStatus::Stored->value)));
    }

    /**
     * @return list<int> rows found applied
     */
    public function applied(): array
    {
        return array_keys(array_filter($this->rows, static fn (OpStatus $s): bool => $s === OpStatus::Stored));
    }
}
