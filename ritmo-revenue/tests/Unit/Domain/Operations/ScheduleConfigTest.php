<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Operations;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Operations\LightRunConfig;
use Ritmo\Domain\Operations\LocalTime;
use Ritmo\Domain\Operations\ScheduleConfig;
use Ritmo\Domain\Operations\SlotKind;
use Ritmo\Tests\Support\Fixtures;

final class ScheduleConfigTest extends TestCase
{
    public function testTheNeutralDefaultIsOneFullRunADayAndNoLightRuns(): void
    {
        $config = ScheduleConfig::defaults();

        self::assertSame('07:30', $config->fullTime->toString());
        self::assertSame([], $config->lightTimes);
        self::assertFalse($config->stopReadsUnderEmergency);
        self::assertSame([[SlotKind::Full, '07:30']], array_map(static fn (array $t): array => [$t[0], $t[1]->toString()], $config->times()));
    }

    public function testTheParityScheduleLoadsWithItsFiveTimes(): void
    {
        $config = ScheduleConfig::fromArray(Fixtures::json('presets/parity-ops.json')['schedule']);

        self::assertSame('07:53', $config->fullTime->toString());
        self::assertSame(['10:53', '13:53', '16:53', '19:53'], array_map(static fn (LocalTime $t): string => $t->toString(), $config->lightTimes));
        self::assertSame([], $config->warnings());
    }

    public function testLightTimesAreSortedAndALightTimeBeforeTheFullRunIsOnlyAWarning(): void
    {
        $config = ScheduleConfig::fromArray(['full_time' => '07:30', 'light_times' => ['18:30', '06:00']]);

        self::assertSame(['06:00', '18:30'], array_map(static fn (LocalTime $t): string => $t->toString(), $config->lightTimes));
        self::assertCount(1, $config->warnings());
        self::assertStringContainsString('06:00', $config->warnings()[0]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'time without minutes' => [['full_time' => '7'], 'schedule.full_time'];
        yield 'hour 24' => [['light_times' => ['24:00']], 'schedule.light_times.0'];
        yield 'too close' => [['full_time' => '07:30', 'light_times' => ['08:00']], 'schedule.light_times'];
        yield 'repeated' => [['light_times' => ['12:00', '12:00']], 'schedule.light_times'];
        yield 'too close across midnight' => [['full_time' => '00:10', 'light_times' => ['23:50']], 'schedule.light_times'];
        yield 'budget out of range' => [['run_budget_seconds' => 1], 'schedule.run_budget_seconds'];
        yield 'unknown key' => [['every' => 5], 'schedule.every'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalidSchedulesAreRefusedWithThePath(array $data, string $path): void
    {
        try {
            ScheduleConfig::fromArray($data);
            self::fail('accepted');
        } catch (InvalidConfig $e) {
            self::assertContains($path, array_map(static fn ($error): string => $error->path, $e->errors()));
        }
    }

    public function testEveryFieldHasALabelAndTheDefaultsMatchTheObject(): void
    {
        foreach ([...ScheduleConfig::fields(), ...LightRunConfig::fields()] as $field) {
            self::assertNotSame('', $field->label, $field->key);
        }
        $light = LightRunConfig::fromArray([]);
        self::assertSame([15, 24, 1, 7, false, true], [$light->movementOverlapMinutes, $light->initialLookbackHours, $light->protectFlipDays, $light->orphanWindowDays, $light->readRulesWithoutMovement, $light->readListingStatus]);
        self::assertSame(5, LightRunConfig::fromArray(Fixtures::json('presets/parity-ops.json')['light_run'])->movementOverlapMinutes);
    }

    public function testALocalTimeIsHoursAndMinutesOnATwentyFourHourClock(): void
    {
        self::assertSame(1433, LocalTime::fromString('23:53')->minutes());
        $this->expectException(\InvalidArgumentException::class);
        LocalTime::fromString('7:53');
    }
}
