<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Resume;

use Ritmo\Application\Write\Journal\OpStatus;
use Ritmo\Application\Write\Journal\StateReader;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Infrastructure\Db\Repository\WriteOpRepository;

/**
 * The first work of every run after the movements are read (the watch stage), whatever the mode:
 * read again every target of the journal rows of earlier runs whose outcome is unknown (or whose
 * deletion-and-rewrite group is incomplete), classify them (ResumePlanner) and record the
 * classification. The old payload is never sent again: a run that writes builds and validates a
 * fresh plan, whose items whose state is already in force drop out (no effect), and writes the
 * repair of the broken groups first. The rows of a broken group stay unresolved until its rewrite
 * is in force, so every run sees the group again (and raises its alert) until it is repaired.
 * Rows of an earlier day are classified for the record only.
 */
final class ResumeService
{
    public function __construct(private readonly WriteOpRepository $ops)
    {
    }

    /**
     * @throws ConnectorException when the re-read fails: then nothing may be written
     */
    public function resume(int $runId, string $propertyCode, StateReader $reader): ResumeReport
    {
        $rows = $this->withoutStoredGroups($this->ops->unsettled($propertyCode, $runId), $runId);
        if ($rows === []) {
            return new ResumeReport([], [], []);
        }
        [$pricing, $pms] = ResumePlanner::targets($rows);
        $report = (new ResumePlanner())->classify($rows, $reader->read($pricing, $pms));
        $open = [];
        foreach ($report->broken as $group) {
            $open[$group->groupKey] = true;
        }
        $kept = [];
        foreach ($rows as $row) {
            if (isset($open[(string) $row['group_key']])) {
                $kept[] = $row['id'];
                continue;
            }
            $status = $report->rows[$row['id']]->value;
            if (isset($report->observed[$row['id']])) {
                $this->ops->observe($row['id'], $status, $report->observed[$row['id']]);
            }
            $this->ops->resolve([$row['id']], $runId, $status);
        }

        return $report->keeping($kept);
    }

    /**
     * A deletion-and-rewrite group whose rows are all stored was read back by its own run: it is
     * marked resolved without a read, and its nights are compared with what that run observed.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function withoutStoredGroups(array $rows, int $runId): array
    {
        $incomplete = [];
        foreach ($rows as $row) {
            if ($row['group_key'] !== '' && $row['status'] !== OpStatus::Stored->value) {
                $incomplete[$row['group_key']] = true;
            }
        }
        $out = [];
        foreach ($rows as $row) {
            if ($row['group_key'] !== '' && !isset($incomplete[$row['group_key']])) {
                $this->ops->resolve([$row['id']], $runId, OpStatus::Stored->value);
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }
}
