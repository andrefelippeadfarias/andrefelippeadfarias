<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Change;

use InvalidArgumentException;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;

/**
 * How to undo one change of a room night: the kind of operation and the values to write.
 * Immutable; stored as JSON in the change log ("undo_plan").
 *
 * Reference: SKILL.md (the change log's "how to undo" column) and the merge semantics of the
 * pricing system (AP 14/10: a write merges into the rule, so a field added is removed only by a
 * deletion).
 */
final class UndoAction
{
    public function __construct(
        public readonly UndoKind $kind,
        public readonly NightFields $fields = new NightFields(),
    ) {
    }

    /**
     * The inverse of a change from $before to $after (both as read, of one system).
     */
    public static function inverse(Provider $provider, NightFields $before, NightFields $after): self
    {
        $before = $before->of($provider);
        $after = $after->of($provider);
        $changed = $before->differs($after);
        if ($changed === []) {
            return new self(UndoKind::Nothing);
        }
        if ($provider === Provider::Listing) {
            return new self(UndoKind::ListingChange, $before->only($changed));
        }
        if ($provider === Provider::Pms) {
            return new self(UndoKind::WriteFields, $before->only($changed));
        }
        if ($before->isEmpty()) {
            return new self(UndoKind::Delete);
        }
        $added = array_diff(array_keys($after->all()), array_keys($before->all()));

        return $added === [] || $after->isEmpty()
            ? new self(UndoKind::WriteFields, $before->only($after->isEmpty() ? array_keys($before->all()) : $changed))
            : new self(UndoKind::DeleteThenWrite, $before);
    }

    /**
     * @return array{kind: string, fields: array<string, int|float|string>}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'fields' => $this->fields->all()];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException on an unknown kind or field
     */
    public static function fromArray(array $data): self
    {
        $kind = UndoKind::tryFrom((string) ($data['kind'] ?? ''));
        if ($kind === null) {
            throw new InvalidArgumentException('Unknown undo kind.');
        }
        $fields = $data['fields'] ?? [];
        if (!is_array($fields)) {
            throw new InvalidArgumentException('The undo fields must be a map.');
        }

        return new self($kind, new NightFields($fields));
    }
}
