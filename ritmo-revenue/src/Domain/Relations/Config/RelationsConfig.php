<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Config;

use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Relations\Relation;

/**
 * The relations part of a rule set: the general settings, the relations between room types and
 * the market bands of rooms. Immutable.
 *
 * Storage form:
 *
 *     {"relations": {"tolerance": {...}, "scan_horizon_days": 60, ..., "items": [relation, ...]},
 *      "price_bands": {"items": [band, ...]}}
 *
 * Every key is optional; the neutral configuration has no relation and no band.
 */
final class RelationsConfig
{
    public const MAX_RELATIONS = 200;
    public const MAX_BANDS = 100;

    /**
     * @param list<Relation>  $relations
     * @param list<PriceBand> $priceBands
     */
    public function __construct(
        public readonly RelationSettings $settings,
        public readonly array $relations = [],
        public readonly array $priceBands = [],
    ) {
    }

    public static function neutral(): self
    {
        return new self(new RelationSettings());
    }

    /**
     * Reads and checks the storage form. Rules that need the rooms (sets, references, cycles) are
     * checked by {@see \Ritmo\Domain\Relations\RelationValidator}.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidConfig with every error found
     */
    public static function fromArray(array $data): self
    {
        $errors = new ErrorList();
        $root = new Reader($data, '', $errors);
        $root->rejectUnknown(['relations', 'price_bands']);
        $relations = $root->group('relations');
        $relations->rejectUnknown(['tolerance', 'scan_horizon_days', 'audit_horizon_days', 'blocked_nights', 'rounding_recheck', 'items']);
        $bands = $root->group('price_bands');
        $bands->rejectUnknown(['items']);

        $config = new self(
            RelationSettings::read($relations),
            self::readItems($relations, self::MAX_RELATIONS, static fn (Reader $item): Relation => Relation::read($item)),
            self::readItems($bands, self::MAX_BANDS, static fn (Reader $item): PriceBand => PriceBand::read($item)),
        );
        self::checkUniqueIds($config, $errors);
        $errors->throwIfAny('relations configuration');

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'relations' => $this->settings->toArray() + [
                'items' => array_map(static fn (Relation $relation): array => $relation->toArray(), $this->relations),
            ],
            'price_bands' => ['items' => array_map(static fn (PriceBand $band): array => $band->toArray(), $this->priceBands)],
        ];
    }

    /**
     * @template T
     *
     * @param callable(Reader): T $read
     *
     * @return list<T>
     */
    private static function readItems(Reader $group, int $max, callable $read): array
    {
        $items = $group->list('items');
        if (count($items) > $max) {
            $group->error('items', ValidationError::RANGE, sprintf('must have at most %d items', $max));
        }
        $parsed = [];
        foreach ($items as $index => $item) {
            $parsed[] = $read($group->item('items', $index, $item));
        }

        return $parsed;
    }

    private static function checkUniqueIds(self $config, ErrorList $errors): void
    {
        $seen = [];
        foreach ($config->relations as $index => $relation) {
            if (isset($seen[$relation->id])) {
                $errors->add('relations.items.' . $index . '.id', ValidationError::UNIQUE, sprintf('repeats the id "%s"', $relation->id));
            }
            $seen[$relation->id] = true;
        }
        $bands = [];
        foreach ($config->priceBands as $index => $band) {
            if (isset($bands[$band->id])) {
                $errors->add('price_bands.items.' . $index . '.id', ValidationError::UNIQUE, sprintf('repeats the id "%s"', $band->id));
            }
            $bands[$band->id] = true;
        }
    }
}
