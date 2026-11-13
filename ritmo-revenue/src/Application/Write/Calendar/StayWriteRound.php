<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Calendar;

use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Stay\Write\PmsChangeCheck;
use Ritmo\Domain\Stay\Write\PmsCheckContext;
use Ritmo\Domain\Stay\Write\StayWritePlan;

/**
 * The PMS side of a run once the validator has spoken: the planned PMS changes checked against
 * this run (with the pricing items the validator accepted, so a pushed change whose pricing item
 * was refused is refused pms_unpaired) and the accepted ones turned into calendar changes for the
 * gate. Shadow and approval modes use the same batch to show what would be written.
 */
final class StayWriteRound
{
    public function __construct(private readonly PmsChangeCheck $check = new PmsChangeCheck())
    {
    }

    /**
     * @param array<string, RoomType> $rooms room code => room
     */
    public function batch(StayWritePlan $plan, PmsCheckContext $context, array $rooms): PmsWriteBatch
    {
        $context = new PmsCheckContext(
            $context->today,
            $context->now,
            $context->rooms,
            $context->inventory,
            $context->frozen,
            $context->ruleTargets + $plan->ruleTargets,
            $context->acceptedPricing,
            $context->allowingOrders,
            $context->reverts,
            $context->maxMinStay,
            $context->proofMaxAgeHours,
            $context->config,
        );
        $result = $this->check->check($plan->pms, $context);

        return new PmsWriteBatch(PmsCalendarChanges::of($result->accepted, $rooms), $result, $plan->frozenOut, count($plan->conflicts));
    }
}
