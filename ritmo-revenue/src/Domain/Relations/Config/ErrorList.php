<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Config;

use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;

/**
 * The validation errors found while reading a relations configuration.
 */
final class ErrorList
{
    /** @var list<ValidationError> */
    private array $errors = [];

    public function add(string $path, string $code, string $message): void
    {
        $this->errors[] = new ValidationError($path, $code, $message);
    }

    /**
     * @return list<ValidationError>
     */
    public function all(): array
    {
        return $this->errors;
    }

    /**
     * @throws InvalidConfig when at least one error was recorded
     */
    public function throwIfAny(string $what): void
    {
        if ($this->errors !== []) {
            throw new InvalidConfig($what, $this->errors);
        }
    }
}
