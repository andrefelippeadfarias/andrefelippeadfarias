<?php

declare(strict_types=1);

namespace Ritmo\Application\Licence;

use Closure;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;

/**
 * ModeLowering over the repositories, the same three steps as a lowering by a person
 * (Mode\ModeService): the property's mode, the event in the change log (actor "licence") and the
 * end of every approval not written yet ($expireApprovals is ApprovalService::expireAll()).
 */
final class RepositoryModeLowering implements ModeLowering
{
    public const ACTOR = 'licence';

    public const APPROVAL_REASON = 'licence_lapsed';

    /**
     * @param Closure(string, string): int $expireApprovals ends the approvals of a property not written yet
     */
    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly OperationEventRepository $events,
        private readonly Closure $expireApprovals,
        private readonly Clock $clock,
    ) {
    }

    public function toShadow(Property $property, string $reason): void
    {
        if ($property->mode === PropertyMode::Shadow) {
            return;
        }
        $this->properties->setMode($property->id, PropertyMode::Shadow);
        $this->events->record($property->id, OperationEventRepository::MODE, $property->mode->value, PropertyMode::Shadow->value, $reason, self::ACTOR, $this->clock->now());
        ($this->expireApprovals)($property->id, self::APPROVAL_REASON);
    }
}
