<?php

declare(strict_types=1);

namespace Ritmo\Application\Mode;

use Ritmo\Application\Approval\ApprovalService;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;

/**
 * What a person does with the emergency switch (screen or REST): engaging it also ends every
 * approval not written yet, of every property, so nothing approved before the emergency is
 * written after it is released; the event is recorded only when the switch really moved. The
 * caller has already checked the nonce and the connections capability, and read the position
 * strictly (a malformed request never releases the switch).
 */
final class EmergencyControl
{
    public const REASON = 'emergency_switch';

    public function __construct(
        private readonly EmergencySwitch $switch,
        private readonly PropertyRepository $properties,
        private readonly ApprovalService $approvals,
        private readonly OperationEventRepository $events,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param string $via    where the person did it ("Screen", "REST")
     * @param string $actor  "user:<id>"
     *
     * @return bool whether the switch is engaged afterwards (the constant keeps it engaged)
     */
    public function set(bool $engage, string $via, string $actor): bool
    {
        $was = $this->switch->isEngaged();
        $engage ? $this->switch->engage() : $this->switch->release();
        $now = $this->switch->isEngaged();
        if ($engage) {
            foreach ($this->properties->all() as $property) {
                $this->approvals->expireAll($property->id, self::REASON);
            }
        }
        if ($was !== $now) {
            $this->events->record('', OperationEventRepository::EMERGENCY, $was ? 'engaged' : 'released', $now ? 'engaged' : 'released', $via, $actor, $this->clock->now());
        }

        return $now;
    }
}
