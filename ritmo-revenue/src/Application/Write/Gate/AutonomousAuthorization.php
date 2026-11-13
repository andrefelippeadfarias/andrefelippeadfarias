<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Gate;

use DateTimeImmutable;
use Ritmo\Domain\Model\PropertyMode;

/**
 * The property writes on its own: valid only while its mode, read fresh, is autonomous and the
 * licence allows writing. It never allows a listing change.
 */
final class AutonomousAuthorization implements WriteAuthorization
{
    /**
     * @param int               $enabledBy user who turned the autonomous mode on
     * @param DateTimeImmutable $enabledAt when
     */
    public function __construct(
        public readonly string $propertyId,
        public readonly int $enabledBy,
        public readonly DateTimeImmutable $enabledAt,
    ) {
    }

    public function refusal(WriteContext $context, string $planHash, DateTimeImmutable $today): ?string
    {
        return match (true) {
            $context->property->id !== $this->propertyId => WriteRefused::WRONG_PROPERTY,
            $context->property->mode === PropertyMode::Shadow => WriteRefused::MODE_SHADOW,
            $context->property->mode !== PropertyMode::Auto => WriteRefused::MODE_NOT_AUTONOMOUS,
            !$context->licenceAllowsWrites => WriteRefused::LICENCE,
            default => null,
        };
    }

    public function isHuman(): bool
    {
        return false;
    }

    public function allowsListingChange(string $roomCode, string $field): bool
    {
        return false;
    }

    public function actor(): string
    {
        return 'autonomous';
    }
}
