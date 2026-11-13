<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Changes;

use Ritmo\Domain\Write\Change\ChangeRecord;

/**
 * The rows of a change-log page grouped by the run that wrote them, newest group first (rows of a
 * group by room, night and field), each with who allowed it ("autonomous", a user id, or an
 * undo). Pure.
 */
final class ChangeGroups
{
    private function __construct()
    {
    }

    /**
     * @param list<ChangeRecord> $rows newest first
     *
     * @return list<array{run_id: int|null, actor: string, user_id: int|null, undo: bool, at: \DateTimeImmutable, rows: list<ChangeRecord>}>
     */
    public static function byRun(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $row->runId === null ? 'row:' . $row->id : 'run:' . $row->runId;
            $groups[$key] ??= [
                'run_id' => $row->runId,
                'actor' => $row->actor,
                'user_id' => self::userId($row->actor),
                'undo' => $row->undoOf !== null,
                'at' => $row->createdAt,
                'rows' => [],
            ];
            $groups[$key]['rows'][] = $row;
        }
        foreach ($groups as &$group) {
            usort($group['rows'], static fn (ChangeRecord $a, ChangeRecord $b): int => [$a->roomCode, $a->night?->getTimestamp(), $a->field] <=> [$b->roomCode, $b->night?->getTimestamp(), $b->field]);
        }
        unset($group);

        return array_values($groups);
    }

    public static function userId(string $actor): ?int
    {
        return preg_match('/^user:(\d{1,18})$/', $actor, $m) === 1 ? (int) $m[1] : null;
    }
}
