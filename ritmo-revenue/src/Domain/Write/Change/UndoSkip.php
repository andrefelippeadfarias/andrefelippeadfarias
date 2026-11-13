<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Change;

use DateTimeImmutable;

/**
 * A night (or listing value) an undo leaves alone, and why. Shown to the person who asked.
 */
final class UndoSkip
{
    /** The night was changed after the plugin's change (by someone else or a later run): never overwritten. */
    public const CONFLICT = 'undo_conflict';
    public const ALREADY_UNDONE = 'already_undone';
    public const PAST_NIGHT = 'past_night';
    public const NOTHING_TO_UNDO = 'nothing_to_undo';
    public const UNKNOWN_ROOM = 'unknown_room';
    /** The records of the night come from more than one change: undo the latest first. */
    public const SEVERAL_CHANGES = 'several_changes';
    /** What the night held cannot be written by the plugin (a price, an expiry). */
    public const NOT_RESTORABLE = 'not_restorable';

    /**
     * @param list<string> $fields the fields concerned (for a conflict: the ones that changed since)
     * @param list<int>    $recordIds
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $subjectKey,
        public readonly string $roomCode,
        public readonly ?DateTimeImmutable $night,
        public readonly array $fields = [],
        public readonly array $recordIds = [],
    ) {
    }
}
