<?php

declare(strict_types=1);

namespace Ritmo\Rest\Editors;

use Closure;
use DateTimeImmutable;
use Ritmo\Admin\Editors\EditorText;
use Ritmo\Admin\Editors\FieldPath;
use Ritmo\Admin\Editors\ValueText;
use Ritmo\Application\Rules\FieldChange;
use Ritmo\Application\Rules\RulesRefused;
use Ritmo\Application\Rules\VersionHistory;
use Ritmo\Domain\Config\Schema;

/**
 * The answers of the rules routes (spec 05, 5.5.6), as [status, body]: the versions of the rules
 * and what changed between two of them, in words (labels, values with their unit; never a dotted
 * key or JSON of the rules). The Versions tab reads the comparison to open the list of changes of
 * a version in place. Read only: saving and restoring stay form actions.
 */
final class EditorEndpoints
{
    /**
     * @param Closure(int): string                $person   display name of a user id
     * @param Closure(DateTimeImmutable): string $instant  a saved-at instant in the site's format
     */
    public function __construct(
        private readonly VersionHistory $history,
        private readonly Closure $person,
        private readonly Closure $instant,
        private readonly string $currency,
    ) {
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function versions(int $page): array
    {
        $versions = $this->history->page(max(1, $page));

        return [200, [
            'total' => $versions['total'],
            'per_page' => VersionHistory::PER_PAGE,
            'versions' => array_map(fn (array $row): array => [
                'version' => $row['version'],
                'saved_at' => ($this->instant)($row['created_at']),
                'by' => ($this->person)($row['created_by']),
                'note' => $row['note'],
                'changes' => $row['changes'],
                'in_force' => $row['in_force'],
            ], $versions['rows']),
        ]];
    }

    /**
     * What changed from version $from to version $to, one row per value.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function compare(int $from, int $to): array
    {
        try {
            $changes = $this->history->compare($from, $to);
            $rules = $this->history->rules($to)->toArray();
        } catch (RulesRefused $refused) {
            return [404, ['code' => 'ritmo_' . $refused->reason, 'message' => EditorText::refusal($refused)]];
        }
        $schema = Schema::ruleSet();
        $rows = array_map(fn (FieldChange $change): array => [
            'label' => FieldPath::label($change->path, $schema, $rules),
            'before' => ValueText::of($change->key, $change->before, $schema, $this->currency),
            'after' => ValueText::of($change->key, $change->after, $schema, $this->currency),
        ], $changes);

        return [200, [
            'from' => $from,
            'to' => $to,
            'changes' => $rows,
            'text' => $rows === []
                ? __('These two versions hold the same values.', 'ritmo-revenue')
                /* translators: 1: number of changes, 2: first version, 3: second version */
                : sprintf(_n('%1$d change from version %2$d to version %3$d.', '%1$d changes from version %2$d to version %3$d.', count($rows), 'ritmo-revenue'), count($rows), $from, $to),
        ]];
    }
}
