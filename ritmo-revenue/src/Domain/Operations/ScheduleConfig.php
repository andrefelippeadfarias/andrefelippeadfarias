<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * When the runs of a property start (section "schedule"; the operations spec nests it under
 * "operations"). Times are local to the property. The neutral default is one full run a day and
 * no light runs: a property reads and decides once a day until its owner adds light runs.
 */
final class ScheduleConfig
{
    public const ROOT = 'schedule';

    public const MAX_LIGHT_TIMES = 8;

    /**
     * @param list<LocalTime> $lightTimes ascending
     */
    public function __construct(
        public readonly LocalTime $fullTime,
        public readonly array $lightTimes = [],
        public readonly int $minSpacingMinutes = 60,
        public readonly int $lateToleranceMinutes = 30,
        public readonly int $retryFailedAfterMinutes = 15,
        public readonly int $runBudgetSeconds = 20,
        public readonly int $tickBudgetSeconds = 40,
        public readonly int $maxMovementLookbackDays = 30,
        public readonly int $stalledAfterMinutes = 20,
        public readonly bool $stopReadsUnderEmergency = false,
    ) {
    }

    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    /**
     * @param array<string, mixed> $data the "schedule" section; missing keys take the defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(self::fields());
        $d = $reader->open(self::ROOT, $data);
        $full = self::time($reader, $d['full_time'], 'schedule.full_time');
        $lights = [];
        foreach ($reader->items($d['light_times'], 'schedule.light_times') as $i => $text) {
            $time = self::time($reader, $text, 'schedule.light_times.' . $i);
            if ($time !== null) {
                $lights[] = $time;
            }
        }
        usort($lights, static fn (LocalTime $a, LocalTime $b): int => $a->minutes() <=> $b->minutes());
        $spacing = $reader->int($d['min_spacing_minutes'], 'schedule.min_spacing_minutes');
        if ($full !== null) {
            self::checkSpacing($reader, [$full, ...$lights], $spacing);
        }
        $config = new self(
            $full ?? LocalTime::fromString('07:30'),
            $lights,
            $spacing,
            $reader->int($d['late_tolerance_minutes'], 'schedule.late_tolerance_minutes'),
            $reader->int($d['retry_failed_after_minutes'], 'schedule.retry_failed_after_minutes'),
            $reader->int($d['run_budget_seconds'], 'schedule.run_budget_seconds'),
            $reader->int($d['tick_budget_seconds'], 'schedule.tick_budget_seconds'),
            $reader->int($d['max_movement_lookback_days'], 'schedule.max_movement_lookback_days'),
            $reader->int($d['stalled_after_minutes'], 'schedule.stalled_after_minutes'),
            $reader->bool($d['stop_reads_under_emergency'], 'schedule.stop_reads_under_emergency'),
        );
        $reader->finish(self::ROOT);

        return $config;
    }

    /**
     * Every configured time with its kind, ascending.
     *
     * @return list<array{0: SlotKind, 1: LocalTime}>
     */
    public function times(): array
    {
        $times = [[SlotKind::Full, $this->fullTime], ...array_map(static fn (LocalTime $t): array => [SlotKind::Light, $t], $this->lightTimes)];
        usort($times, static fn (array $a, array $b): int => $a[1]->minutes() <=> $b[1]->minutes());

        return $times;
    }

    /**
     * Advice that does not make the schedule invalid: a light time before the full time runs as a
     * full run (the first run of a day is always full) and the full run still runs at its time.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $early = array_filter($this->lightTimes, fn (LocalTime $t): bool => $t->minutes() < $this->fullTime->minutes());

        return $early === [] ? [] : [sprintf(
            'The light run at %s comes before the full run at %s: it will run as a full run, and the full run still runs at its time.',
            implode(', ', array_map(static fn (LocalTime $t): string => $t->toString(), $early)),
            $this->fullTime->toString()
        )];
    }

    /**
     * @return list<Field>
     */
    public static function fields(): array
    {
        return [
            new Field('schedule', FieldType::Group, 'Schedule', 'When the runs of each property start, in the property\'s local time.'),
            new Field('schedule.full_time', FieldType::Text, 'Full run at', 'Local time of the daily full run (reads everything, decides the whole horizon).', '07:30', pattern: LocalTime::PATTERN),
            new Field('schedule.light_times', FieldType::ListOf, 'Light runs at', 'Local times of the light runs (only what moved since the previous run). Empty: no light runs.', [], max: self::MAX_LIGHT_TIMES, unique: true),
            new Field('schedule.light_times.*', FieldType::Text, 'Time', required: true, pattern: LocalTime::PATTERN),
            new Field('schedule.min_spacing_minutes', FieldType::Integer, 'Minutes between runs', 'Every two configured times are at least this far apart.', 60, min: 30, max: 720, unit: 'minutes'),
            new Field('schedule.late_tolerance_minutes', FieldType::Integer, 'Late after', 'A run that starts later than this after its time raises a warning (it still runs).', 30, min: 5, max: 240, unit: 'minutes'),
            new Field('schedule.retry_failed_after_minutes', FieldType::Integer, 'Retry a failed run after', 'One retry of a run that failed because a system could not be reached. Zero: never.', 15, min: 0, max: 180, unit: 'minutes'),
            new Field('schedule.run_budget_seconds', FieldType::Integer, 'Work per property per tick', 'Seconds of work for one property in one tick; the run continues on the next tick.', 20, min: 5, max: 120, unit: 'seconds'),
            new Field('schedule.tick_budget_seconds', FieldType::Integer, 'Work per tick', 'Seconds of work of one scheduler tick (keep it under the server\'s execution limit).', 40, min: 10, max: 300, unit: 'seconds'),
            new Field('schedule.max_movement_lookback_days', FieldType::Integer, 'Read missed bookings for at most', 'After a long stop, the first run reads the bookings changed in at most this many days; an older gap is reported.', 30, min: 1, max: 90, unit: Field::UNIT_DAYS),
            new Field('schedule.stalled_after_minutes', FieldType::Integer, 'Scheduler stalled after', 'A run waiting this long without any scheduler tick raises a warning.', 20, min: 10, max: 240, unit: 'minutes'),
            new Field('schedule.stop_reads_under_emergency', FieldType::Boolean, 'Emergency switch stops reads', 'Off: with the emergency switch engaged the scheduled runs still read and watch (and write nothing). On: they do not run at all.', false),
        ];
    }

    private static function time(ConfigReader $reader, mixed $value, string $path): ?LocalTime
    {
        $text = $reader->string($value, $path);

        return preg_match(LocalTime::PATTERN, $text) === 1 ? LocalTime::fromString($text) : null;
    }

    /**
     * @param list<LocalTime> $times
     */
    private static function checkSpacing(ConfigReader $reader, array $times, int $spacing): void
    {
        $minutes = array_map(static fn (LocalTime $t): int => $t->minutes(), $times);
        sort($minutes);
        $count = count($minutes);
        for ($i = 0; $i < $count; ++$i) {
            $gap = $i + 1 < $count ? $minutes[$i + 1] - $minutes[$i] : $minutes[0] + 1440 - $minutes[$i];
            if ($count > 1 && $gap < $spacing) {
                $reader->add('schedule.light_times', ValidationError::RANGE, sprintf('every two run times must be at least %d minutes apart', $spacing));

                return;
            }
        }
    }
}
