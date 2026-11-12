<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

/**
 * One family of rules of the validator, run on every item that passed the shape checks.
 */
interface ItemCheck
{
    public function check(ItemFacts $f): void;
}
