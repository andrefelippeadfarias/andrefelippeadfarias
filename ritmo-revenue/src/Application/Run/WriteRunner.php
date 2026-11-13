<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Application\ConnectorSource;
use Ritmo\Application\Write\Execute\WriteExecutor;
use Ritmo\Application\Write\Execute\WriteOutcome;
use Ritmo\Application\Write\Execute\WriteRun;
use Ritmo\Application\Write\Gate\TonightState;
use Ritmo\Application\Write\Gate\WriteAuthorization;
use Ritmo\Application\Write\Gate\WriteContext;
use Ritmo\Application\Write\Gate\WriteGate;
use Ritmo\Application\Write\Journal\StateReader;
use Ritmo\Application\Write\Resume\BrokenGroup;
use Ritmo\Application\Write\Resume\ResumeReport;
use Ritmo\Application\Write\Resume\ResumeService;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\ValidatedPlan;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\RunRepository;

/**
 * The write of one run, once its plan is authorized: first what earlier runs left unsettled is
 * read back and classified (never sent again from the journal); a group stopped between its
 * deletion and its rewrite is repaired first, alone, through the validator and the gate; else the
 * plan goes to the gate (which issues the token or refuses) and the executor sends it in series,
 * slice after slice, stopping at the first failure.
 */
final class WriteRunner
{
    public function __construct(
        private readonly ConnectorSource $connectors,
        private readonly WriteGate $gate,
        private readonly WriteExecutor $executor,
        private readonly ResumeService $resume,
        private readonly RunRepository $runs,
        private readonly CapabilityCheck $capabilities,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Reads back what earlier runs left unsettled.
     *
     * @throws \Ritmo\Contracts\Exception\ConnectorException when the systems cannot be read: nothing is written
     */
    public function settle(RunContext $context): ResumeReport
    {
        return $this->resume->resume($context->runId, $context->property->id, new StateReader($this->connectors->pricing(), $this->connectors->pms()));
    }

    /**
     * The repair of the groups an earlier run left open, as a plan for today, validated; null when
     * there is nothing to repair or the repair does not pass.
     *
     * @param list<BrokenGroup> $broken
     */
    public function repairPlan(RunContext $context, RebuiltPlan $rebuilt, array $broken): ?ValidatedPlan
    {
        $rooms = [];
        foreach ($context->rooms as $room) {
            $rooms[$room->code] = $room;
        }
        $items = [];
        foreach ($broken as $group) {
            $room = $rooms[$group->roomCode] ?? null;
            if ($room instanceof RoomType) {
                array_push($items, ...$group->repairItems($room, Days::key($context->today)));
            }
        }
        if ($items === []) {
            return null;
        }

        return $rebuilt->revalidate(new Plan($context->property->id, $context->today, $this->clock->now(), $items, []));
    }

    /**
     * @param list<string> $recalculate listing ids to recalculate before their writes
     *
     * @throws \Ritmo\Application\Write\Gate\WriteRefused when the gate refuses the plan
     */
    public function write(RunContext $context, RebuiltPlan $rebuilt, ValidatedPlan $plan, WriteAuthorization $auth, Closure $stillAllowed, array $recalculate): WriteOutcome
    {
        $heartbeat = static fn (): bool => $context->stillHeld() && $stillAllowed() === true;
        $issued = $this->gate->issue($plan, $auth, new WriteContext(
            $context->property,
            $context->rooms,
            $context->capturedAt() ?? $this->clock->now(),
            $this->tonight($context, $rebuilt),
            $heartbeat,
            $this->connectors->pricingPmsName(),
            true,
            fn (int $user): bool => $this->capabilities->userCan($user, CapabilityCheck::APPROVE),
        ), [], $recalculate);

        return $this->executor->execute(new WriteRun(
            $context->runId,
            $context->property,
            $plan,
            $issued,
            $this->connectors->pricing(),
            $this->connectors->pms(),
            $heartbeat,
            null,
            $this->runs->find($context->runId)['started_at'] ?? $this->clock->now(),
        ));
    }

    /**
     * Tonight of every room as the run read it: the price in force and the date rule.
     *
     * @return array<string, TonightState>
     */
    private function tonight(RunContext $context, RebuiltPlan $rebuilt): array
    {
        $key = Days::key($context->today);
        $out = [];
        foreach ($context->rooms as $room) {
            $night = $rebuilt->inputs->calendar[$room->code][$key] ?? null;
            $out[$room->code] = new TonightState($room->code, $night?->price, $rebuilt->inputs->override($room->code, $context->today));
        }

        return $out;
    }

    /**
     * The listings of the plan's rooms that the pricing system has not recalculated on the
     * property's today: they are recalculated before their writes when the quota allows.
     *
     * @return list<string>
     */
    public static function staleListings(RunContext $context, ValidatedPlan $plan): array
    {
        $rooms = [];
        foreach ([...$plan->plan->items, ...$plan->plan->deletions] as $entry) {
            $rooms[$entry->roomCode] = true;
        }
        $listings = $context->listings();
        $out = [];
        foreach ($context->rooms as $room) {
            if (!isset($rooms[$room->code]) || $room->pricingRef === '') {
                continue;
            }
            $refreshed = $listings[$room->code]['last_refreshed_at'] ?? null;
            $day = is_string($refreshed) ? Days::key((new DateTimeImmutable($refreshed))->setTimezone(new DateTimeZone($context->property->timezone))) : null;
            if ($day === null || $day < Days::key($context->today)) {
                $out[] = $room->pricingRef;
            }
        }

        return $out;
    }
}
