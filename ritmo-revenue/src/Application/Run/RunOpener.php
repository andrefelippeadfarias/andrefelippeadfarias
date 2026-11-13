<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use DateTimeImmutable;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Application\ShadowRun\RunOutcome;
use Ritmo\Contracts\Logger;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;

/**
 * Opens a run and gives back the context its stages work with: the rules in force when it
 * started (kept for the whole run), the property's room types mapped to the PMS, the day of the
 * property, the nights it decides (plan.horizon_nights from today), its kind and the mode it runs
 * in. A run left open is reopened with what its completed stages left.
 */
final class RunOpener
{
    public function __construct(
        private readonly RoomTypeRepository $roomTypes,
        private readonly RuleSetRepository $ruleSets,
        private readonly RunRepository $runs,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return RunContext|RunOutcome a failed outcome when no room type is mapped to the PMS
     */
    public function open(Property $property, string $kind, PropertyMode $mode, DateTimeImmutable $today): RunContext|RunOutcome
    {
        $now = $this->clock->now();
        $version = $this->ruleSets->currentVersion();
        $rules = $this->rules($version);
        $runId = $this->runs->start($property->id, $kind, $mode, $version, $now, $today);
        $this->logger->info('A run starts.', ['run_id' => $runId, 'property_id' => $property->id, 'kind' => $kind, 'mode' => $mode->value]);
        $rooms = $this->rooms($property);
        if ($rooms === []) {
            $error = 'No room type of the property is mapped to the PMS.';
            $this->runs->finish($runId, RunRepository::FAILED, [], $now, $error);

            return new RunOutcome(RunOutcome::FAILED, $runId, '', $error);
        }

        return new RunContext($runId, $property, $rooms, $rules, $today, self::horizon($rules, $today), '', [], [], $kind, $mode, $version);
    }

    /**
     * The context of a run left open, from the stage it completed last.
     *
     * @param array{id: int, kind: string, mode: PropertyMode, ruleset_version: int|null, run_day: DateTimeImmutable|null, stage: string, summary: array<string, mixed>} $open
     */
    public function reopen(Property $property, array $open): RunContext
    {
        $rules = $this->rules($open['ruleset_version']);
        $today = $open['run_day'] ?? $this->clock->today($property->timezone);
        $this->logger->info('A run resumes.', ['run_id' => $open['id'], 'property_id' => $property->id, 'stage' => $open['stage']]);

        return new RunContext($open['id'], $property, $this->rooms($property), $rules, $today, self::horizon($rules, $today), $open['stage'], $open['summary'], $this->runs->state($open['id']), $open['kind'], $open['mode'], $open['ruleset_version']);
    }

    private static function horizon(RuleSet $rules, DateTimeImmutable $today): DateRange
    {
        return DateRange::nights($today, $rules->plan()->horizonNights);
    }

    /**
     * @return list<RoomType>
     */
    private function rooms(Property $property): array
    {
        return array_values(array_filter($this->roomTypes->forProperty($property->id), static fn (RoomType $room): bool => $room->pmsRef !== ''));
    }

    private function rules(?int $version): RuleSet
    {
        return ($version === null ? null : $this->ruleSets->find($version)) ?? RuleSet::defaults();
    }
}
