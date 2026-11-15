<?php

declare(strict_types=1);

namespace Ritmo\Application\Mode;

use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;

/**
 * Leaving shadow mode for approval is the step after which an approved plan is written: the
 * person types the property's id and confirms that no other tool writes prices or stay rules for
 * the property (two tools writing at once undo each other). The screen and the REST route both
 * ask this before ModeService::change().
 */
final class LeaveShadowConfirmation
{
    private function __construct()
    {
    }

    /**
     * @throws ModeRefused (leave_shadow_unconfirmed) when shadow -> approval is not confirmed
     */
    public static function check(Property $property, PropertyMode $to, string $typed, bool $soleWriter): void
    {
        if ($property->mode !== PropertyMode::Shadow || $to !== PropertyMode::Approval) {
            return;
        }
        if (!hash_equals($property->id, trim($typed)) || !$soleWriter) {
            throw new ModeRefused(ModeRefused::LEAVE_SHADOW, 'To leave shadow mode, type the property\'s id and confirm that no other tool writes prices for it.');
        }
    }
}
