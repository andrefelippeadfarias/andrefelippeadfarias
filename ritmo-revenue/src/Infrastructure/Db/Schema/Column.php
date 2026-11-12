<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Schema;

use InvalidArgumentException;
use Ritmo\Infrastructure\Db\Statement;

/**
 * One column of a plugin table. Columns are NOT NULL unless made nullable().
 */
final class Column
{
    private function __construct(
        public readonly string $name,
        public readonly ColumnType $type,
        public readonly int $length = 0,
        public readonly bool $nullable = false,
        public readonly int|float|string|null $default = null,
    ) {
        Statement::identifier($name);
    }

    public static function id(): self
    {
        return new self('id', ColumnType::Id);
    }

    public static function bigInt(string $name): self
    {
        return new self($name, ColumnType::BigInt);
    }

    public static function int(string $name): self
    {
        return new self($name, ColumnType::Int);
    }

    public static function bool(string $name): self
    {
        return new self($name, ColumnType::Bool);
    }

    public static function float(string $name): self
    {
        return new self($name, ColumnType::Float);
    }

    /**
     * @param int $length maximum length in characters, 1 to 190 (so the column can be indexed everywhere)
     */
    public static function text(string $name, int $length): self
    {
        if ($length < 1 || $length > 190) {
            throw new InvalidArgumentException('A text column is 1 to 190 characters long; use longText() beyond that.');
        }

        return new self($name, ColumnType::Text, $length);
    }

    public static function longText(string $name): self
    {
        return new self($name, ColumnType::LongText);
    }

    public static function date(string $name): self
    {
        return new self($name, ColumnType::Date);
    }

    public static function dateTime(string $name): self
    {
        return new self($name, ColumnType::DateTime);
    }

    public function nullable(): self
    {
        if ($this->type === ColumnType::Id) {
            throw new InvalidArgumentException('The id column cannot be nullable.');
        }

        return new self($this->name, $this->type, $this->length, true, $this->default);
    }

    /**
     * @throws InvalidArgumentException for the types that cannot carry a default everywhere, or a default of the wrong kind
     */
    public function default(int|float|string $value): self
    {
        if (in_array($this->type, [ColumnType::Id, ColumnType::LongText, ColumnType::Date, ColumnType::DateTime], true)) {
            throw new InvalidArgumentException(sprintf('A %s column cannot have a default.', $this->type->value));
        }
        if ($this->type->isNumeric() === is_string($value)) {
            throw new InvalidArgumentException(sprintf('The default of "%s" does not match its type.', $this->name));
        }
        if (is_string($value) && preg_match('/^[A-Za-z0-9_ .:-]*$/', $value) !== 1) {
            throw new InvalidArgumentException('A text default is limited to letters, digits, spaces and "_.:-".');
        }

        return new self($this->name, $this->type, $this->length, $this->nullable, $value);
    }
}
