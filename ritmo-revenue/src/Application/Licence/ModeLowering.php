<?php

declare(strict_types=1);

namespace Ritmo\Application\Licence;

use Ritmo\Domain\Model\Property;

/**
 * Puts a property back in shadow mode on the plugin's own decision (the licence lapsed): the
 * change is recorded and the approvals not written yet end, like any lowering of the mode.
 */
interface ModeLowering
{
    public function toShadow(Property $property, string $reason): void;
}
