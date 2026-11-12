<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Schema;

/**
 * The column types the plugin's tables use, each with its MySQL and SQLite spelling.
 */
enum ColumnType: string
{
    /** Auto-incremented primary key. */
    case Id = 'id';
    case BigInt = 'bigint';
    case Int = 'int';
    /** 0 or 1. */
    case Bool = 'bool';
    case Float = 'float';
    /** Short text with a maximum length. */
    case Text = 'text';
    /** Long text (JSON documents, messages). */
    case LongText = 'longtext';
    /** A night: "YYYY-MM-DD". */
    case Date = 'date';
    /** An instant in UTC: "YYYY-MM-DD HH:MM:SS". */
    case DateTime = 'datetime';

    public function mysql(int $length): string
    {
        return match ($this) {
            self::Id => 'bigint(20) unsigned',
            self::BigInt => 'bigint(20)',
            self::Int => 'int(11)',
            self::Bool => 'tinyint(1)',
            self::Float => 'double',
            self::Text => sprintf('varchar(%d)', $length),
            self::LongText => 'longtext',
            self::Date => 'date',
            self::DateTime => 'datetime',
        };
    }

    public function sqlite(): string
    {
        return match ($this) {
            self::Id, self::BigInt, self::Int, self::Bool => 'INTEGER',
            self::Float => 'REAL',
            self::Text, self::LongText, self::Date, self::DateTime => 'TEXT',
        };
    }

    public function isNumeric(): bool
    {
        return in_array($this, [self::Id, self::BigInt, self::Int, self::Bool, self::Float], true);
    }
}
