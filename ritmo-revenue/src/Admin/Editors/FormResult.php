<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

/**
 * What reading one posted rules form gave: the rules with every value that read, the values that
 * did not read (their path and a reason code; the rules keep the value they had) and the paths
 * that changed.
 */
final class FormResult
{
    /**
     * @param array<string, mixed>                         $rules
     * @param list<array{path: string, code: string}>      $errors
     * @param list<string>                                  $touched
     */
    public function __construct(public readonly array $rules, public readonly array $errors, public readonly array $touched)
    {
    }
}
