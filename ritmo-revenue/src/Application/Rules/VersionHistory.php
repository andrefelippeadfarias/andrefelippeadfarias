<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules;

use DateTimeImmutable;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Config\Schema;
use Ritmo\Infrastructure\Db\Repository\EditorEventRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;

/**
 * The versions of the rules for the Versions tab (spec 05, 5.5.6): who saved each one, when,
 * from where (editor, wizard, restore, import, update), its note and how many values it changed
 * from the version before; and the comparison of any two versions. Read only: history is never
 * rewritten, a restore is a new version.
 */
final class VersionHistory
{
    public const PER_PAGE = 20;

    public function __construct(
        private readonly RuleSetRepository $ruleSets,
        private readonly EditorEventRepository $events,
        private readonly PropertyRepository $properties,
    ) {
    }

    /**
     * @return array{rows: list<array{version: int, created_at: DateTimeImmutable, created_by: int, note: string, source: string, restored_from: int|null, changes: int|null, in_force: bool}>, total: int}
     */
    public function page(int $page): array
    {
        $current = $this->ruleSets->currentVersion();
        if ($current === null) {
            return ['rows' => [], 'total' => 0];
        }
        $all = $this->ruleSets->history(max(1, $current));
        $slice = array_slice($all, max(0, $page - 1) * self::PER_PAGE, self::PER_PAGE);
        $sources = $this->sources();
        $rows = [];
        foreach ($slice as $row) {
            $event = $sources[$row['version']] ?? null;
            $rows[] = [
                'version' => $row['version'],
                'created_at' => $row['created_at'],
                'created_by' => $row['created_by'],
                'note' => $row['note'],
                'source' => (string) ($event['source'] ?? ''),
                'restored_from' => is_int($event['restored_from'] ?? null) ? $event['restored_from'] : null,
                'changes' => $row['version'] === 1 ? null : $this->changesCount($row['version'] - 1, $row['version']),
                'in_force' => $row['version'] === $current,
            ];
        }

        return ['rows' => $rows, 'total' => count($all)];
    }

    /**
     * What changed from one version to another.
     *
     * @return list<FieldChange>
     *
     * @throws RulesRefused when a version does not exist or does not read
     */
    public function compare(int $from, int $to): array
    {
        return RuleDiff::between($this->rules($from)->toArray(), $this->rules($to)->toArray(), Schema::ruleSet());
    }

    /**
     * @throws RulesRefused when the version does not exist or does not read
     */
    public function rules(int $version): RuleSet
    {
        try {
            $rules = $this->ruleSets->find($version);
        } catch (InvalidConfig) {
            $rules = null;
        }

        return $rules ?? throw new RulesRefused(RulesRefused::UNKNOWN_VERSION, ['version' => $version]);
    }

    private function changesCount(int $from, int $to): ?int
    {
        try {
            return count($this->compare($from, $to));
        } catch (RulesRefused) {
            return null;
        }
    }

    /**
     * The details of the version events, by version (they are the same for every property).
     *
     * @return array<int, array<string, mixed>>
     */
    private function sources(): array
    {
        $properties = $this->properties->all();
        $propertyId = $properties === [] ? '' : $properties[0]->id;
        $sources = [];
        foreach ($this->events->latest($propertyId, [EditorEventRepository::RULES_VERSION], 500) as $event) {
            $sources[(int) $event['to']] ??= $event['details'];
        }

        return $sources;
    }
}
