<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Channel;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\Cadence\StepRecord;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Write\Confirm\PercentSlack;

/**
 * When a written pace step counts as delivered to the channels. Both must hold:
 *
 * 1. the room was pushed at or after the step was written (a step stored to the minute only once
 *    its whole minute ended before the push), and
 * 2. the price the PMS holds for the night, read after that push, is the step's: a step up at
 *    least its value less PercentSlack, a step down at most its value plus PercentSlack.
 *
 * Until then the step stays pending (the cadence holds the next step of that night in the same
 * direction); a step pending longer than the configured hours is overdue (an alert).
 *
 * Reference: pace.marcar_envio (the push instant alone). The PMS price is a plugin rule (task rule
 * 9(j), divergence C3 of docs/VERIFY.md): a push after the write does not prove the written value
 * went out when the pricing system did not recalculate in between.
 */
final class ChannelArrival
{
    public const NO_PUSH_SINCE_WRITE = 'no_push_since_write';
    public const PMS_PRICE_UNKNOWN = 'pms_price_unknown';
    public const PMS_PRICE_OUTSIDE_STEP = 'pms_price_outside_step';

    /**
     * @throws InvalidArgumentException when the overdue limit is not positive
     */
    public function __construct(private readonly int $overdueHours = 30)
    {
        if ($overdueHours < 1) {
            throw new InvalidArgumentException('A step becomes overdue after at least one hour.');
        }
    }

    /**
     * @param array<array-key, StepRecord>     $steps     steps not yet on the channel, by any key the caller keeps
     * @param array<string, DateTimeImmutable> $pushedAt  room => last push (PushTimes)
     * @param array<string, float|null>        $pmsPrices "ROOM|YYYY-MM-DD" => price the PMS holds, read after the push
     * @param array<string, float>             $bases     room => listing base
     *
     * @throws InvalidArgumentException when the base of a step's room is missing
     */
    public function check(array $steps, array $pushedAt, array $pmsPrices, array $bases, DateTimeImmutable $now): ArrivalReport
    {
        $delivered = [];
        $pending = [];
        $overdue = [];
        foreach ($steps as $key => $step) {
            if ($step->onChannelAt !== null) {
                continue;
            }
            if (!isset($bases[$step->roomCode])) {
                throw new InvalidArgumentException(sprintf('The base of room "%s" is missing.', $step->roomCode));
            }
            $push = $pushedAt[$step->roomCode] ?? null;
            $reason = $this->pendingReason($step, $push, $pmsPrices[PlanItem::keyOf($step->roomCode, $step->night)] ?? null, $bases[$step->roomCode]);
            if ($reason === null && $push !== null) {
                $delivered[$key] = $push;
                continue;
            }
            $pending[$key] = (string) $reason;
            if ($now->getTimestamp() - $step->writtenAt->getTimestamp() > $this->overdueHours * 3600) {
                $overdue[] = $key;
            }
        }

        return new ArrivalReport($delivered, $pending, $overdue);
    }

    private function pendingReason(StepRecord $step, ?DateTimeImmutable $push, ?float $pmsPrice, float $base): ?string
    {
        if ($push === null || $step->writtenAtLatest() > $push) {
            return self::NO_PUSH_SINCE_WRITE;
        }
        if ($pmsPrice === null) {
            return self::PMS_PRICE_UNKNOWN;
        }
        $slack = PercentSlack::of($step->priceTo, $base);
        $inside = $step->direction === StepDirection::Up ? $pmsPrice >= $step->priceTo - $slack : $pmsPrice <= $step->priceTo + $slack;

        return $inside ? null : self::PMS_PRICE_OUTSIDE_STEP;
    }
}
