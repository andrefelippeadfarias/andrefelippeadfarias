<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\RuleSetFields;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Relations\Config\RelationFields;
use Ritmo\Domain\Relations\RelationType;

/**
 * The forms of one room relation and one price band, built from the fields the relations module
 * declares (RelationFields): the relation's own fields and the parameters of its kind. The id,
 * the property and the kind are set by the screen, not typed.
 */
final class RelationForm
{
    /** Fields the screen sets itself. */
    public const HIDDEN = ['id', 'property', 'type'];

    private function __construct()
    {
    }

    public static function relationSchema(RelationType $type): Schema
    {
        return new Schema([
            ...RelationFields::relation(),
            new Field('params', FieldType::Group, 'Settings of this kind of relation'),
            ...RuleSetFields::nested('params', RelationFields::params($type)),
        ]);
    }

    public static function bandSchema(): Schema
    {
        return new Schema(RelationFields::priceBand());
    }

    /**
     * The top-level keys a person types in a form of the schema.
     *
     * @return list<string>
     */
    public static function typed(Schema $schema): array
    {
        return array_values(array_filter(array_map(static fn (Field $f): string => $f->key, $schema->children('')), static fn (string $k): bool => !in_array($k, self::HIDDEN, true)));
    }

    /**
     * A new id for a relation or band of a room: "<prefix>-<room>", made unique among $taken.
     *
     * @param list<string> $taken
     */
    public static function newId(string $prefix, string $room, array $taken): string
    {
        $base = substr($prefix . '-' . strtolower((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $room)), 0, 56);
        $id = $base;
        for ($n = 2; in_array($id, $taken, true); ++$n) {
            $id = $base . '-' . $n;
        }

        return $id;
    }
}
