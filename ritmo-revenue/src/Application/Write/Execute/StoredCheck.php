<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Execute;

use Ritmo\Application\Write\Journal\NightRule;
use Ritmo\Application\Write\Journal\OpStatus;
use Ritmo\Application\Write\Journal\StoredState;

/**
 * The read-back of a written step: every night of every target holds the intended value of every
 * compared field (a deletion: no rule) -> stored; anything else -> mismatch, never rewritten in the
 * same run. Reference: beds24._conferir; AP 10/11 (every written date compared by script).
 */
final class StoredCheck
{
    /**
     * @param array<string, array<string, array{state: array<string, mixed>|null, fields: list<string>}>> $intended
     *
     * @return array{0: OpStatus, 1: array<string, array<string, array<string, mixed>|null>>} status and what was read
     */
    public static function compare(array $intended, StoredState $fresh, bool $pms): array
    {
        $status = OpStatus::Stored;
        $observed = [];
        foreach ($intended as $target => $nights) {
            foreach ($nights as $night => $want) {
                $now = $pms ? ($fresh->pms[$target][$night] ?? null) : ($fresh->pricing[$target][$night] ?? null);
                $observed[$target][$night] = $now;
                if (!NightRule::holds($now, $want['state'], $want['fields'])) {
                    $status = OpStatus::Mismatch;
                }
            }
        }

        return [$status, $observed];
    }
}
