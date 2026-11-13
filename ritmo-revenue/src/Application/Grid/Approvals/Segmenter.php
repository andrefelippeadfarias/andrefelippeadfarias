<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid\Approvals;

use Ritmo\Domain\Support\Days;

/**
 * Groups the entries of a request into segments (decision D8): sorted by room order then night,
 * a segment is a maximal run of consecutive nights of one room with the same reason, the same
 * direction and the same special key (tonight, tomorrow and holiday nights apart). A deletion and
 * its rewrite of the same night stay in one segment. Pure.
 */
final class Segmenter
{
    private function __construct()
    {
    }

    /**
     * @param list<EntryRow> $entries night entries (listing values are shown apart)
     * @param list<string>   $roomOrder room codes in the property's order; unknown rooms go last
     *
     * @return list<Segment>
     */
    public static function segments(array $entries, array $roomOrder): array
    {
        $rank = array_flip($roomOrder);
        usort($entries, static fn (EntryRow $a, EntryRow $b): int => [$rank[$a->room] ?? PHP_INT_MAX, $a->room, $a->night?->getTimestamp(), $a->kind === 'deletion' ? 0 : 1]
            <=> [$rank[$b->room] ?? PHP_INT_MAX, $b->room, $b->night?->getTimestamp(), $b->kind === 'deletion' ? 0 : 1]);

        $segments = [];
        $current = [];
        foreach ($entries as $entry) {
            $previous = $current === [] ? null : $current[count($current) - 1];
            if ($previous !== null && !self::continues($previous, $entry)) {
                $segments[] = new Segment($previous->room, $current);
                $current = [];
            }
            $current[] = $entry;
        }
        if ($current !== []) {
            $segments[] = new Segment($current[0]->room, $current);
        }

        return $segments;
    }

    private static function continues(EntryRow $previous, EntryRow $next): bool
    {
        if ($previous->room !== $next->room || $previous->night === null || $next->night === null) {
            return false;
        }
        $gap = Days::diff($previous->night, $next->night);
        if ($gap === 0) {
            return $previous->group === $next->group;
        }

        return $gap === 1
            && $previous->reason === $next->reason
            && $previous->direction === $next->direction
            && $previous->special === $next->special
            && $previous->held === $next->held;
    }
}
