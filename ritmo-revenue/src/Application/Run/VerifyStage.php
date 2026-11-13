<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Application\ConnectorSource;
use Ritmo\Application\ShadowRun\AfterWriteStage;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Application\ShadowRun\StageResult;
use Ritmo\Application\Verify\DeliveryService;
use Ritmo\Application\Verify\NightConfirmation;
use Ritmo\Application\Verify\RecordService;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Pricing\Cadence\StepProposal;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Write\Channel\ChannelArrival;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Infrastructure\Db\Repository\WriteOpRepository;
use Ritmo\Infrastructure\Db\Repository\WrittenStepRepository;

/**
 * Confirms and records what the run wrote: only the nights the read-back found stored enter the
 * change log (before, after and the inverse operation), the step ledger and the observations;
 * then the steps written by earlier runs are checked against the channel (the room's push after
 * the step and the PMS price inside the step's band; operations spec 9(j)). A run that wrote
 * nothing and has no step waiting makes no call here.
 */
final class VerifyStage implements AfterWriteStage
{
    public const NAME = 'verify';

    public function __construct(
        private readonly WriteOpRepository $ops,
        private readonly RecordService $record,
        private readonly WrittenStepRepository $steps,
        private readonly ConnectorSource $connectors,
        private readonly Clock $clock,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(RunContext $context): StageResult
    {
        $now = $this->clock->now();
        $write = is_array($context->state['write'] ?? null) ? $context->state['write'] : [];
        $confirmed = JournalNights::confirmed($this->ops->ofRun($context->runId), $now);
        $recorded = ['changes' => 0, 'steps' => 0];
        if ($confirmed !== [] && is_string($write['actor'] ?? null)) {
            $outcome = $this->record->record(
                $context->property->id,
                $confirmed,
                array_map(static fn (array $p): StepProposal => StepProposal::fromArray($p), (array) ($write['proposals'] ?? [])),
                array_map('floatval', (array) ($write['bases'] ?? [])) + array_map(static fn (RoomType $r): float => $r->base, self::byCode($context->rooms)),
                $write['actor'],
                $context->runId,
                $now,
            );
            $recorded = ['changes' => count($outcome->changeIds), 'steps' => count($outcome->stepIds)];
        }
        $delivery = ['delivered' => 0, 'pending' => 0, 'overdue' => 0];
        if ($this->steps->pending($context->property->id) !== []) {
            $rooms = self::byCode($context->rooms);
            $report = (new DeliveryService($this->steps, $this->connectors->pricing(), $this->connectors->pms(), new ChannelArrival($context->rules->verify()->deliveryOverdueHours)))
                ->confirm($context->property->id, $rooms, $now);
            $delivery = ['delivered' => count($report->delivered), 'pending' => count($report->pending), 'overdue' => count($report->overdue)];
        }

        $pms = count(array_filter($confirmed, static fn (NightConfirmation $night): bool => $night->written->provider === Provider::Pms));

        return new StageResult(['nights_confirmed' => count($confirmed) - $pms, 'pms_nights_confirmed' => $pms, 'recorded' => $recorded, 'delivery' => $delivery], []);
    }

    /**
     * @param list<RoomType> $rooms
     *
     * @return array<string, RoomType>
     */
    private static function byCode(array $rooms): array
    {
        $out = [];
        foreach ($rooms as $room) {
            $out[$room->code] = $room;
        }

        return $out;
    }
}
