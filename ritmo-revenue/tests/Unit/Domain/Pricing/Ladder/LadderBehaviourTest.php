<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing\Ladder;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Pricing\Cadence\CadenceConfig;
use Ritmo\Domain\Pricing\Cadence\SoldBase;
use Ritmo\Domain\Pricing\Cadence\StepCadence;
use Ritmo\Domain\Pricing\Cadence\StepLedger;
use Ritmo\Domain\Pricing\Cadence\StepRecord;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Pricing\Ladder\LadderConfig;
use Ritmo\Domain\Pricing\Ladder\LadderItem;
use Ritmo\Domain\Pricing\Ladder\LadderItemBuilder;
use Ritmo\Domain\Pricing\Ladder\LadderLine;
use Ritmo\Domain\Pricing\Ladder\LadderNightEvaluator;
use Ritmo\Domain\Pricing\Ladder\LadderRoomQuote;
use Ritmo\Domain\Pricing\Ladder\LastMinuteMarketFloor;
use Ritmo\Domain\Pricing\Ladder\WeekendFloorOverride;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Tests\Support\ParityNames;

/**
 * Behaviours of the ladder beyond the golden cases: switches of the configuration, the plan item
 * the ladder hands to the plan and the reports of a ruler gap.
 */
final class LadderBehaviourTest extends TestCase
{
    public function testARulerGapIsReportedOnlyWhenTheCalendarSaysTheStrategyWouldPrice(): void
    {
        $night = new DateTimeImmutable('2027-03-06');
        $data = Reference::parity()['ladder'];
        $data['strategies'][0]['valid_to'] = '2027-03-31';
        $strategy = LadderConfig::fromArray($data)->strategies[0];
        $ladder = Reference::ladder($strategy);
        $orders = Reference::orders(['o' => ['id' => $strategy->authorityOrderId, 'tipo' => 'regra', 'quartos' => ['*'], 'de' => '2026-11-10', 'ate' => '2027-03-31']]);
        $evaluate = static fn (?string $label) => (new LadderNightEvaluator(
            $ladder,
            Reference::ruler(),
            Reference::hardFloor(),
            Reference::relations($ladder),
            static fn (DateTimeImmutable $n): ?string => $label,
        ))->evaluate($night, 5, [], $orders);

        self::assertTrue($evaluate('weekend')->rulerGap);
        self::assertFalse($evaluate('weekday')->rulerGap, 'no rule prices weekdays');
        self::assertFalse($evaluate(null)->rulerGap);
    }

    public function testADisabledStrategyPricesNothingAndHasNoLastMinuteFloor(): void
    {
        $data = Reference::parity()['ladder'];
        $data['strategies'][0]['enabled'] = false;
        $strategy = LadderConfig::fromArray($data)->strategies[0];
        $ladder = Reference::ladder($strategy);
        $orders = Reference::orders(['o' => ['id' => $strategy->authorityOrderId, 'tipo' => 'regra', 'quartos' => ['*'], 'de' => '2026-11-10', 'ate' => '2027-02-28']]);
        $night = new DateTimeImmutable('2026-11-13');
        $row = new PriceNight($night, 2006.0, 2006.0, 2006.0, null, 2, 7);
        $evaluator = new LadderNightEvaluator($ladder, Reference::ruler(), Reference::hardFloor(), Reference::relations($ladder));
        $floor = new LastMinuteMarketFloor($ladder, Reference::ruler(), Reference::hardFloor(), new HolidayCalendar(Reference::rules()));

        self::assertSame([], $evaluator->evaluate($night, 3, [ParityNames::room('low') => $row], $orders)->quotes);
        self::assertNull($floor->floorFor(ParityNames::room('low'), new DateTimeImmutable('2026-11-17'), 3, $orders));
    }

    public function testTheWeekendOverrideNeedsTheStrategyAndAWeekendNightOutsideHolidays(): void
    {
        $rules = Reference::rules();
        $override = new WeekendFloorOverride(Reference::strategy(), $rules->qualityFloor(), new PriceFactor($rules), new HolidayCalendar($rules));
        $orders = Reference::orders(['o' => ['id' => Reference::strategy()->authorityOrderId, 'tipo' => 'regra', 'quartos' => ['*'], 'de' => '2026-11-10', 'ate' => '2027-02-28']]);
        $room = Reference::room(ParityNames::room('low'));

        self::assertTrue($override->applies($room, new DateTimeImmutable('2026-11-13'), $orders));
        self::assertFalse($override->applies($room, new DateTimeImmutable('2026-11-13'), new OrderBook()), 'without the order');
        self::assertFalse($override->applies($room, new DateTimeImmutable('2026-11-17'), $orders), 'a weekday');
        self::assertFalse($override->applies($room, new DateTimeImmutable('2026-11-20'), $orders), 'a holiday');
        self::assertFalse($override->applies(Reference::room(ParityNames::room('plain')), new DateTimeImmutable('2026-11-13'), $orders), 'no quality tag');
        $floor = (new Floors($rules))->hardFloor($room, new DateTimeImmutable('2026-11-17'));
        self::assertSame($floor, $override->standardFloor($floor, $room, new DateTimeImmutable('2026-11-17'), $orders));
    }

    public function testTheItemLocksTheCeilingAndCarriesItsAuthorityAndStep(): void
    {
        $outcome = $this->builder(new CadenceConfig())->build(...$this->downCase(new StepLedger()));
        $item = $outcome->item;

        self::assertInstanceOf(LadderItem::class, $item);
        self::assertSame($item->minPrice, $item->maxPrice);
        self::assertSame([LadderItem::REASON, LadderRoomQuote::RELATION_BETWEEN], $item->reasonCodes);
        self::assertSame(StepDirection::Down, $item->step->direction);
        $plan = $item->toPlanItem();
        self::assertSame('LADDER_PRICE', $plan->reasonCode);
        self::assertSame(['strategy' => 'plan-nov-feb', 'order' => Reference::strategy()->authorityOrderId], $plan->reasonParams['authority']);
        self::assertSame('ladder', $plan->reasonParams['step']['source_status']);
        self::assertSame(2006.0, $plan->before?->price);
    }

    public function testWithoutTheLockOnlyTheFloorIsWritten(): void
    {
        $data = Reference::parity()['ladder'];
        $data['strategies'][0]['lock_ceiling'] = false;
        $builder = new LadderItemBuilder(LadderConfig::fromArray($data)->strategies[0], new StepCadence(), new CadenceConfig(), 0.03);

        self::assertNull($builder->build(...$this->downCase(new StepLedger()))->item?->maxPrice);
    }

    public function testTheFirstLadderStepExceptionCanBeSwitchedOff(): void
    {
        $night = new DateTimeImmutable('2026-11-13');
        $ledger = new StepLedger([new StepRecord(ParityNames::room('low'), $night, 2100.0, 2006.0, StepDirection::Down, 'behind', 2, SoldBase::Room, 7, new DateTimeImmutable('2026-11-10T10:00:00Z'))]);

        self::assertNotNull($this->builder(new CadenceConfig())->build(...$this->downCase($ledger))->item, 'the first ladder step goes at once');
        $held = $this->builder(new CadenceConfig(firstLadderStepImmediate: false))->build(...$this->downCase($ledger));
        self::assertNull($held->item);
        self::assertSame('CADENCE_NOT_ON_CHANNEL', $held->line->hold);
    }

    public function testTonightNeverRisesEvenToTheLadderPrice(): void
    {
        $args = $this->downCase(new StepLedger());
        $args[2] = 0;
        $args[3] = new PriceNight(new DateTimeImmutable('2026-11-13'), 900.0, 900.0, 900.0, null, 2, 7);
        $outcome = $this->builder(new CadenceConfig())->build(...$args);

        self::assertNull($outcome->item);
        self::assertSame(LadderLine::TODAY_NEVER_UP, $outcome->line->hold);
    }

    private function builder(CadenceConfig $config): LadderItemBuilder
    {
        return new LadderItemBuilder(Reference::strategy(), new StepCadence($config), $config, 0.03);
    }

    /**
     * The low room on a weekend night: the ladder asks 1086.57 while 2006 is live, three days out.
     *
     * @return array<int, mixed>
     */
    private function downCase(StepLedger $ledger): array
    {
        $night = new DateTimeImmutable('2026-11-13');
        $quote = new LadderRoomQuote(ParityNames::room('low'), 'weekend', 1086.57, 669.0, 669.0, 0, false, 2, 7, 560.0, 'safety_limit', 0.6157, false, null, 1000.0, [LadderRoomQuote::RELATION_BETWEEN]);

        return [Reference::room(ParityNames::room('low')), $quote, 3, new PriceNight($night, 2006.0, 2006.0, 2006.0, null, 2, 7), null, $ledger, new DateTimeImmutable('2026-11-10T12:00:00Z'), true, 5000.0];
    }
}
