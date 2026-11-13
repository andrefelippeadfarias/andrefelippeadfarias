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
use Ritmo\Application\Write\Resume\BrokenGroup;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\ValidatedPlan;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\WriteOpRepository;

/**
 * The write of one run, once its plan is authorized: a group stopped between its deletion and its
 * rewrite (found by the watch stage's read-back) is repaired first, alone, through the validator
 * and the gate; else the plan goes to the gate (which issues the token or refuses) and the
 * executor sends it in series, slice after slice, stopping at the first failure. The write of a
 * run happens once: a request that finds journal rows of its own run (the process died in the
 * middle of the write) never sends again from what that run collected.
 */
final class WriteRunner
{
    /** Seconds kept free before the request's time limit: a call never starts in them. */
    private const TIME_MARGIN_SECONDS = 10;

    /**
     * @param (Closure(): (int|null))|null $timeLeft seconds this request may still work (it may extend its
     *                                               limit first); null or a null answer: no limit
     */
    public function __construct(
        private readonly ConnectorSource $connectors,
        private readonly WriteGate $gate,
        private readonly WriteExecutor $executor,
        private readonly WriteOpRepository $ops,
        private readonly QuotaRunStart $runStart,
        private readonly CapabilityCheck $capabilities,
        private readonly Clock $clock,
        private readonly ?Closure $timeLeft = null,
    ) {
    }

    /**
     * @throws WriteInterrupted when this run already journaled writes: an earlier request of the
     *                          same run died while writing
     */
    public function guardOnce(RunContext $context): void
    {
        if ($this->ops->ofRun($context->runId) !== []) {
            throw new WriteInterrupted('The write of this run was interrupted (the process stopped while writing): it is never sent again from the same reads; the next run reads the systems again and sends only what is missing.');
        }
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
            $this->deadline(),
            $this->runStart->of($context),
        ));
    }

    /**
     * The instant after which no call starts: the request's time limit less a margin.
     */
    private function deadline(): ?DateTimeImmutable
    {
        $seconds = $this->timeLeft === null ? null : ($this->timeLeft)();

        return $seconds === null || $seconds <= 0 ? null : $this->clock->now()->modify(sprintf('+%d seconds', max(0, $seconds - self::TIME_MARGIN_SECONDS)));
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
