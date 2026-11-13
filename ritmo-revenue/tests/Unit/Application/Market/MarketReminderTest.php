<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Market;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Market\MarketFreshness;
use Ritmo\Application\Market\MarketReminder;
use Ritmo\Application\Market\UploadedFile;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Market\Series\MarketConfig;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Support\Days;

/**
 * What the full run hands to the market alerts (market spec 1.7): the age of the newest price of
 * the coming nights (market_stale) and the nights an enabled sales ladder would price without a
 * value (market_gap), from stored data only.
 */
final class MarketReminderTest extends TestCase
{
    private const HEADER = ['competitor', 'check_in', 'check_out', 'price', 'price_basis', 'room', 'currency', 'captured_at', 'source', 'available'];

    public function testWithoutPricesTheMarketIsStaleAndWithoutALadderThereIsNoGap(): void
    {
        $world = new MarketWorld();

        $check = self::reminder($world)->check(self::property(), RuleSet::defaults(), MarketConfig::neutral(), Days::date('2026-11-13'));

        self::assertSame([true, null, []], [$check['stale'], $check['age_days'], $check['gaps']]);
    }

    public function testAnEnabledLadderWithoutValuesHasGapsUntilTheImportFillsThem(): void
    {
        $world = new MarketWorld();
        $world->addCompetitors(12);
        $rules = self::rulesWithLadder();
        $today = Days::date('2026-11-13');

        $before = self::reminder($world)->check(self::property(), $rules, MarketConfig::neutral(), $today);
        self::assertCount(30, $before['gaps'], 'every night of the next 30 cites a point that has no value yet');
        self::assertSame([['cheapest', 50.0]], array_map(static fn ($p): array => [$p->series, $p->percentile], $before['gaps'][0]['missing']));

        $rows = [];
        for ($i = 1; $i <= 12; ++$i) {
            for ($day = 0; $day < 30; ++$day) {
                $checkIn = Days::add($today, $day);
                $rows[] = [sprintf('comp-%02d', $i), Days::key($checkIn), Days::key(Days::add($checkIn, 1)), (string) (400 + 10 * $i), 'total', 'cheapest', 'BRL', '2026-11-12 09:30', 'booking', '1'];
            }
        }
        $records = [self::HEADER, ...$rows];
        $preview = $world->importService->preview(MarketWorld::PROPERTY, new UploadedFile('p.csv', hash('sha256', (string) json_encode($records)), 100, $records), 7);
        $world->importService->commit(MarketWorld::PROPERTY, $preview->token, 7);

        $after = self::reminder($world)->check(self::property(), $rules, MarketConfig::neutral(), $today);
        self::assertSame([false, 1, []], [$after['stale'], $after['age_days'], $after['gaps']]);
    }

    private static function reminder(MarketWorld $world): MarketReminder
    {
        return new MarketReminder(new MarketFreshness($world->samples), $world->ruler);
    }

    private static function property(): Property
    {
        return new Property(MarketWorld::PROPERTY, 'Inn One', 'America/Sao_Paulo', 'BRL');
    }

    private static function rulesWithLadder(): RuleSet
    {
        $rules = RuleSet::defaults()->toArray();
        $rules['ladder']['enabled'] = true;
        $rules['ladder']['strategies'] = [[
            'id' => 'every-night',
            'enabled' => true,
            'rules' => [['night_types' => ['weekday', 'weekend', 'holiday'], 'rooms' => ['A'], 'opening' => ['series' => 'cheapest', 'percentile' => 50]]],
        ]];

        return RuleSet::fromArray(Schema::ruleSet()->withDefaults($rules));
    }
}
