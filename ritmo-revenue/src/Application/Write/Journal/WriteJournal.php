<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Journal;

use Ritmo\Application\Write\Gate\IssuedToken;
use Ritmo\Application\Write\Schedule\WriteStep;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Repository\WriteOpRepository;

/**
 * The write-ahead journal of a run: every step is inserted as planned before the first call (a
 * crash before any call still leaves the full list of what was intended), each one is marked
 * sending before its own call, and finished after it. Rows keep the nights, the state read before,
 * the state the step leaves, the body (never headers) and hashes of the token, the plan and the
 * body (the body hash finds a body refused run after run).
 */
final class WriteJournal
{
    public function __construct(private readonly WriteOpRepository $ops, private readonly Clock $clock)
    {
    }

    /**
     * @return array<int, int> step number => journal row id
     */
    public function plan(int $runId, string $propertyCode, IssuedToken $issued, StoredState $before): array
    {
        $rows = [];
        $now = $this->clock->now();
        foreach ($issued->steps as $step) {
            $rows[$step->number] = $this->ops->insertPlanned([
                'run_id' => $runId,
                'property_code' => $propertyCode,
                'provider' => $step->operation->isPms() ? 'pms' : 'pricing',
                'operation' => $step->operation->value,
                'target_ref' => mb_substr(implode(',', array_keys($step->nights)), 0, 64, 'UTF-8'),
                'group_key' => $step->groupKey,
                'slice_number' => $step->number,
                'label' => $step->label,
                'dates' => $step->dates(),
                'payload' => ['room' => $step->roomCode, 'nights' => $step->nights, 'entries' => $step->payload],
                'before_state' => $before->before($step),
                'intended_state' => $before->intended($step),
                'token_id' => $issued->journalId(),
                'plan_hash' => $issued->planHash,
                'body_hash' => self::bodyHash($step),
            ], $now);
        }

        return $rows;
    }

    public function sending(int $row): void
    {
        $this->ops->markSending($row, $this->clock->now());
    }

    public function finish(int $row, OpStatus $status, ?int $httpStatus = null, ?string $error = null): void
    {
        $this->ops->finish($row, $status->value, $httpStatus, $error, $this->clock->now());
    }

    /**
     * @param array<string, mixed> $observed the state read back
     */
    public function observe(int $row, OpStatus $status, array $observed): void
    {
        $this->ops->observe($row, $status->value, $observed);
    }

    /**
     * Runs in a row, the latest first, the same body of the property was refused.
     */
    public function refusals(string $propertyCode, WriteStep $step): int
    {
        return $this->ops->refusalStreak($propertyCode, self::bodyHash($step));
    }

    public static function bodyHash(WriteStep $step): string
    {
        return substr(hash('sha256', json_encode([$step->operation->value, $step->nights, $step->payload], JSON_THROW_ON_ERROR)), 0, 32);
    }
}
