<?php

declare(strict_types=1);

namespace Ritmo\Domain\Config;

/**
 * Kinds of field a configuration schema can hold.
 */
enum FieldType: string
{
    /** A fixed set of named children. */
    case Group = 'group';

    /** User-defined keys, every entry with the same shape (the child key is "*"). */
    case Map = 'map';

    /** An ordered list, every item with the same shape (the child key is "*"). */
    case ListOf = 'list';

    case Integer = 'int';
    case Number = 'float';
    case Boolean = 'bool';
    case Text = 'string';

    /** One value out of Field::$enum. */
    case Choice = 'enum';

    /** A calendar date as "YYYY-MM-DD". */
    case Date = 'date';

    /**
     * A group of fields whose shape depends on a sibling field (the parameters of a room
     * relation follow its type). The schema only checks that it is a group; the module that owns
     * it describes and checks its fields (RuleSetValidator runs that module's reader).
     */
    case Variant = 'variant';

    public function isContainer(): bool
    {
        return $this === self::Group || $this === self::Map || $this === self::ListOf;
    }
}
