<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules;

use Ritmo\Domain\Config\Schema;

/**
 * Three-way merge of a rules draft (spec 05, 5.5.5): the values a person changed in a draft that
 * nobody changed since the draft started are applied on top of the rules in force; a value both
 * changed (to different values) is a conflict. A whole list is one value here: two people
 * editing the same list conflict, never mix.
 */
final class RuleMerge
{
    /**
     * @param array<string, mixed> $base   the rules the draft started from
     * @param array<string, mixed> $mine   the draft
     * @param array<string, mixed> $theirs the rules in force now
     *
     * @return array{rules: array<string, mixed>, conflicts: list<array{path: string, key: string|null, mine: mixed, theirs: mixed}>}
     */
    public static function merge(array $base, array $mine, array $theirs, Schema $schema): array
    {
        $theirChanges = [];
        foreach (RuleDiff::between($base, $theirs, $schema, true) as $change) {
            $theirChanges[$change->path] = $change;
        }
        $merged = $theirs;
        $conflicts = [];
        foreach (RuleDiff::between($base, $mine, $schema, true) as $change) {
            $theirs = self::theirsAround($theirChanges, $change->path);
            if ($theirs !== null) {
                if (!RuleDiff::same(self::at($merged, $change->path), $change->after) || $change->kind !== $theirs->kind) {
                    $conflicts[] = ['path' => $change->path, 'key' => $change->key, 'mine' => $change->after, 'theirs' => self::at($merged, $change->path)];
                }
                continue;
            }
            $merged = self::put($merged, explode('.', $change->path), $change->kind === FieldChange::REMOVED, $change->after);
        }

        return ['rules' => $merged, 'conflicts' => $conflicts];
    }

    /**
     * Their change at the same path, or at a path that contains it or that it contains.
     *
     * @param array<string, FieldChange> $theirChanges
     */
    private static function theirsAround(array $theirChanges, string $path): ?FieldChange
    {
        foreach ($theirChanges as $theirPath => $change) {
            if ($theirPath === $path || str_starts_with($path, $theirPath . '.') || str_starts_with($theirPath, $path . '.')) {
                return $change;
            }
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private static function at(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }

    /**
     * @param array<int|string, mixed> $data
     * @param list<string>             $segments
     *
     * @return array<int|string, mixed>
     */
    private static function put(array $data, array $segments, bool $remove, mixed $value): array
    {
        $segment = array_shift($segments);
        if ($segments === []) {
            if ($remove) {
                unset($data[$segment]);
            } else {
                $data[$segment] = $value;
            }

            return $data;
        }
        $data[$segment] = self::put(is_array($data[$segment] ?? null) ? $data[$segment] : [], $segments, $remove, $value);

        return $data;
    }
}
