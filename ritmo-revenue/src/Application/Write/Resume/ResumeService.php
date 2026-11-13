<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Resume;

use Ritmo\Application\Write\Journal\StateReader;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Infrastructure\Db\Repository\WriteOpRepository;

/**
 * The first work of a write run after an earlier one stopped or died: read again every target of
 * the unsettled journal rows, classify them (ResumePlanner) and record the classification. The
 * old payload is never sent again: the run builds and validates a fresh plan, whose items whose
 * state is already in force drop out (no effect), and writes the repair of the broken groups
 * first. Rows of an earlier day are classified for the record only.
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
        $rows = $this->ops->unsettled($propertyCode, $runId);
        if ($rows === []) {
            return new ResumeReport([], [], []);
        }
        [$pricing, $pms] = ResumePlanner::targets($rows);
        $report = (new ResumePlanner())->classify($rows, $reader->read($pricing, $pms));
        foreach ($report->rows as $id => $status) {
            $this->ops->resolve([$id], $runId, $status->value);
        }

        return $report;
    }
}
