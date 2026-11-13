<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Ritmo\Domain\Config\Schema;

/**
 * What a rules form is drawn with: the schema, the part of the rules it edits (null for every
 * field: the relation and band forms), the rules in force (for the "Changed" mark), the rooms of
 * the property (room fields become room choices), the currency of amounts, the errors by path
 * and whether the fields are read-only (a person without the capability).
 */
final class FieldContext
{
    /**
     * @param array<string, mixed>  $inForce
     * @param array<string, string> $rooms   code => name
     * @param array<string, string> $errors  path => sentence
     */
    public function __construct(
        public readonly Schema $schema,
        public readonly ?FormScope $scope,
        public readonly array $inForce,
        public readonly array $rooms,
        public readonly string $currency,
        public readonly array $errors = [],
        public readonly bool $readOnly = false,
        public readonly string $nameRoot = 'f',
    ) {
    }

    public function holds(string $key): bool
    {
        return $this->scope === null || $this->scope->holds($key);
    }
}
