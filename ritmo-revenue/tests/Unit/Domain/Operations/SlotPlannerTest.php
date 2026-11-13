<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Operations;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Operations\LocalTime;
use Ritmo\Domain\Operations\ScheduleConfig;
use Ritmo\Domain\Operations\Slot;
use Ritmo\Domain\Operations\SlotKind;
use Ritmo\Domain\Operations\SlotPlanner;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;

/**
 * The slots of a schedule in the property's timezone, and parity of latestAtOrBefore and
 * nextFullAfter with the reference (cota_recalculo.inicio_rodada and proxima_rotina_completa) on
 * the goldens of tests/fixtures/golden/m3/ops/ (O48).
 */
final class SlotPlannerTest extends TestCase
{
    private static function planner(string $timezone, string $full = '07:30', array $lights = ['12:30', '18:30']): SlotPlanner
    {
        return new SlotPlanner(ScheduleConfig::fromArray(['full_time' => $full, 'light_times' => $lights]), $timezone);
    }

    private static function utc(string $instant): DateTimeImmutable
    {
        return new DateTimeImmutable($instant);
    }

    public function testASlotFallsOnTheLocalTimeOfTheProperty(): void
    {
        $slots = self::planner('America/Sao_Paulo')->slotsOn(Days::date('2026-11-12'));

        self::assertSame(['full 10:30', 'light 15:30', 'light 21:30'], array_map(static fn (Slot $s): string => $s->kind->value . ' ' . $s->at->format('H:i'), $slots));
        self::assertSame('2026-11-12T07:30|full', $slots[0]->key());
    }

    public function testATimeInsideTheSpringForwardGapFallsOnTheFirstValidInstant(): void
    {
        $at = self::planner('America/New_York', '02:30', [])->occurrence(Days::date('2026-03-08'), LocalTime::fromString('02:30'));

        self::assertSame('2026-03-08T07:00:00+00:00', $at->format(DATE_ATOM), '03:00 local, the end of the gap');
    }

    public function testARepeatedTimeWhenTheClocksGoBackIsOneSlotAtTheEarlierInstant(): void
    {
        $planner = self::planner('Europe/Lisbon', '01:30', []);
        $slots = $planner->slotsBetween(self::utc('2026-10-24T22:00:00Z'), self::utc('2026-10-25T03:00:00Z'));

        self::assertCount(1, $slots);
        self::assertSame('2026-10-25T00:30:00+00:00', $slots[0]->at->format(DATE_ATOM));
    }

    public function testSlotsBetweenAreAfterTheStartUpToTheEndInclusiveAscending(): void
    {
        $planner = self::planner('America/Sao_Paulo');
        $slots = $planner->slotsBetween(self::utc('2026-11-12T10:30:00Z'), self::utc('2026-11-13T10:30:00Z'));

        self::assertSame(['2026-11-12T12:30', '2026-11-12T18:30', '2026-11-13T07:30'], array_map(static fn (Slot $s): string => $s->localKey(), $slots));
        self::assertSame([], $planner->slotsBetween(self::utc('2026-11-13T10:30:00Z'), self::utc('2026-11-12T10:30:00Z')));
    }

    public function testLatestIsInclusiveAndNextFullIsStrict(): void
    {
        $planner = self::planner('America/Sao_Paulo');

        self::assertSame('2026-11-12T07:30', $planner->latestAtOrBefore(self::utc('2026-11-12T10:30:00Z'))?->localKey());
        self::assertSame('2026-11-11T18:30', $planner->latestAtOrBefore(self::utc('2026-11-12T10:29:59Z'))?->localKey());
        self::assertSame('2026-11-13T07:30', $planner->nextFullAfter(self::utc('2026-11-12T10:30:00Z'))->localKey());
        self::assertSame(SlotKind::Full, $planner->nextFullAfter(self::utc('2026-11-12T10:29:59Z'))->kind);
        self::assertSame('2026-11-12T12:30', $planner->nextAfter(self::utc('2026-11-12T10:30:00Z'))->localKey());
    }

    public function testAnInvalidTimezoneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::planner('Mars/Base');
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function goldens(): array
    {
        $cases = [];
        foreach (['latest' => 'cota_recalculo.inicio_rodada.json', 'next_full' => 'cota_recalculo.proxima_rotina_completa.json'] as $what => $file) {
            foreach (Fixtures::json('golden/m3/ops/' . $file)['cases'] as $case) {
                $cases[$what . ' ' . $case['name']] = [$what, $case['input']['agora'], $case['expected']];
            }
        }

        return $cases;
    }

    #[DataProvider('goldens')]
    public function testTheParityScheduleMatchesTheReference(string $what, string $now, string $expected): void
    {
        $parity = Fixtures::json('presets/parity-ops.json');
        $planner = new SlotPlanner(ScheduleConfig::fromArray($parity['schedule']), $parity['timezone']);
        $slot = $what === 'latest' ? $planner->latestAtOrBefore(self::utc($now)) : $planner->nextFullAfter(self::utc($now));

        self::assertNotNull($slot);
        self::assertSame($expected, $slot->at->format('Y-m-d\TH:i:s\Z'));
    }
}
