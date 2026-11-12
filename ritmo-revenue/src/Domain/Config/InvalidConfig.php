<?php

declare(strict_types=1);

namespace Ritmo\Domain\Config;

use InvalidArgumentException;

/**
 * A rule set or preset was rejected. Carries every error found, not only the first.
 */
final class InvalidConfig extends InvalidArgumentException
{
    /** @var list<ValidationError> */
    private array $errors;

    /**
     * @param list<ValidationError> $errors
     */
    public function __construct(string $what, array $errors)
    {
        $this->errors = $errors;
        $shown = array_map(static fn (ValidationError $error): string => (string) $error, array_slice($errors, 0, 5));
        $more = count($errors) > 5 ? sprintf(' (and %d more)', count($errors) - 5) : '';

        parent::__construct(sprintf('Invalid %s: %s%s', $what, implode('; ', $shown), $more));
    }

    /**
     * @return list<ValidationError>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
