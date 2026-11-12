<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Plan;

use DateTimeImmutable;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Calendar\Stay\StayConfig;
use Ritmo\Domain\Calendar\Stay\StayRules;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Orders\OrderReader;
use Ritmo\Domain\Plan\FreshCalendar;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanConfig;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Plan\Port\StrategyGuard;
use Ritmo\Domain\Plan\ValidationContext;
use Ritmo\Domain\Plan\ValidationResult;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\FixedClock;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * The sample inn of EngineSample with the M2 context of the validator. "Now" is Wednesday
 * 3 March 2027, noon UTC (Lisbon), so tonight is the 3rd.
 *
 * Stay: one night on Sunday, Tuesday and Thursday, two on the other days, from 1 March 2027; the
 * orphan night is on; Saturday is closed for arrival and departure with its own stay of two, its
 * free side opens when Friday is sold out; Friday stays open for departure in the Deluxe room.
 */
final class PlanSample
{
    public const NOW = '2027-03-03T12:00:00+00:00';
    public const TODAY = '2027-03-03';

    public static function clock(): FixedClock
    {
        return new FixedClock(self::NOW);
    }

    public static function stay(): StayRules
    {
        $config = StayConfig::fromArray([
            'arrival_rule' => ['enabled' => true, 'valid_from' => '2027-03-01', 'by_weekday' => ['0' => 2, '1' => 1, '2' => 2, '3' => 1, '4' => 2, '5' => 2, '6' => 1]],
            'orphan' => ['next_night' => true, 'on_holidays' => false],
            'closed_days' => [[
                'weekday' => 5,
                'restriction' => 'no_checkin_checkout',
                'min_stay' => 2,
                'orphan_side' => ['enabled' => true, 'on_holidays' => false, 'when_closed_day_sold_out' => 'no_checkin', 'when_previous_sold_out' => 'no_checkout'],
            ]],
            'open_days' => [['weekday' => 4, 'rooms' => ['Deluxe']]],
        ]);

        return new StayRules($config, new HolidayCalendar(EngineSample::rules()), [EngineSample::deluxe(), EngineSample::standard()]);
    }

    /**
     * @param list<array<string, mixed>> $rows orders in the plugin's keys
     */
    public static function orders(array $rows): OrderBook
    {
        $reader = new OrderReader();

        return new OrderBook(array_map(static fn (array $row) => $reader->read($row + ['from' => '2027-03-01', 'to' => '2027-12-31', 'rooms' => ['*'], 'given_on' => '2027-03-02']), $rows));
    }

    public static function context(
        ?FreshCalendar $calendar = null,
        ?OrderBook $orders = null,
        ?StrategyGuard $strategies = null,
        bool $acceptStale = false,
        array $weekly = [],
    ): ValidationContext {
        return new ValidationContext($calendar, $orders ?? new OrderBook(), $strategies ?? new \Ritmo\Domain\Plan\Port\NoStrategy(), self::stay(), null, null, $weekly, $acceptStale);
    }

    /**
     * A Deluxe item next Wednesday unless changed.
     *
     * @param array<string, mixed> $with constructor arguments by name
     */
    public static function item(array $with = []): PlanItem
    {
        $args = $with + [
            'roomCode' => 'Deluxe',
            'date' => Days::date('2027-03-10'),
            'minPrice' => 900.0,
            'reasonCode' => 'pace_step',
            'before' => new NightState(1000.0, 600.0, 3000.0, 1),
        ];
        if (is_string($args['date'])) {
            $args['date'] = Days::date($args['date']);
        }

        return new PlanItem(...$args);
    }

    /**
     * @param list<PlanItem> $items
     */
    public static function validate(array $items, ?ValidationContext $context = null, ?PlanConfig $config = null, array $deletions = [], array $listing = []): ValidationResult
    {
        $clock = self::clock();
        $plan = new Plan('inn', Days::date(self::TODAY), $clock->now(), $items, $deletions, $listing);

        return (new Validator(EngineSample::rules(), $clock, $config))->validate($plan, EngineSample::property(), [EngineSample::deluxe(), EngineSample::standard()], $context ?? self::context());
    }

    public static function instant(string $text): DateTimeImmutable
    {
        return new DateTimeImmutable($text);
    }
}
