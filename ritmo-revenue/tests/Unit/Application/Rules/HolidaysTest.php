<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Rules\Calendar\Easter;
use Ritmo\Application\Rules\Calendar\HolidayCatalogue;
use Ritmo\Application\Rules\Calendar\HolidayList;
use Ritmo\Application\Rules\Calendar\HolidayProposal;
use Ritmo\Application\Rules\Calendar\HolidayProposals;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;

final class HolidaysTest extends TestCase
{
    public function testEasterSundayMatchesThePublishedDates(): void
    {
        foreach (['2025-04-20', '2026-04-05', '2027-03-28', '2028-04-16', '2030-04-21', '2035-03-25'] as $date) {
            self::assertSame($date, Days::key(Easter::sunday((int) substr($date, 0, 4))));
        }
        if (function_exists('easter_days')) {
            for ($year = 2025; $year <= 2035; ++$year) {
                self::assertSame(Days::key(Days::add(Days::date($year . '-03-21'), easter_days($year))), Days::key(Easter::sunday($year)), (string) $year);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Easter::sunday(1500);
    }

    public function testEveryShippedListReadsAndGivesEveryHolidayOfElevenYears(): void
    {
        $catalogue = self::catalogue();
        self::assertSame(['BR', 'PT'], $catalogue->countries());
        foreach ($catalogue->countries() as $country) {
            $list = $catalogue->load($country);
            self::assertSame($country, $list->country);
            for ($year = 2025; $year <= 2035; ++$year) {
                $dates = $list->datesIn($year);
                self::assertCount(count($list->holidays), $dates);
                foreach ($dates as $holiday) {
                    self::assertSame($year, (int) $holiday['date']->format('Y'), $country . ' ' . $holiday['id']);
                }
            }
        }
    }

    public function testTheShippedDatesEqualTheOfficialDatesTheFilesCarry(): void
    {
        foreach (self::catalogue()->countries() as $country) {
            $file = Fixtures::json('../../presets/holidays/' . $country . '.json');
            self::assertNotEmpty($file['checked'], $country);
            foreach ($file['checked'] as $year => $official) {
                $computed = [];
                foreach (HolidayList::fromArray($file)->datesIn((int) $year) as $holiday) {
                    $computed[$holiday['id']] = Days::key($holiday['date']);
                }
                ksort($computed);
                ksort($official);
                self::assertSame($official, $computed, $country . ' ' . $year);
            }
        }
        self::assertSame([2026, 2027, 2028], array_keys(Fixtures::json('../../presets/holidays/BR.json')['checked']));
    }

    public function testTheDocumentedExamplesOfDaysOffAndBridges(): void
    {
        $nights = static function (array $holidays, bool $bridges, int $year = 2026): array {
            $list = HolidayList::fromArray(['format' => 'ritmo-revenue-holidays', 'version' => 1, 'country' => 'XX', 'names' => ['en' => 'Test'], 'days_off' => [5, 6], 'bridges' => true, 'holidays' => $holidays]);

            return array_map(static fn (HolidayProposal $p): array => [Days::key($p->firstNight()), Days::key($p->lastNight()), $p->nights(), $p->onWeekend], HolidayProposals::forYears($list, [$year], $bridges));
        };
        $one = static fn (string $md): array => [['id' => 'h', 'rule' => ['fixed' => $md], 'names' => ['en' => 'H']]];

        self::assertSame([['2026-09-03', '2026-09-05', 3, false]], $nights($one('09-04'), true), 'a Friday holiday: Thursday to Saturday nights');
        self::assertSame([['2026-12-23', '2026-12-26', 4, false]], $nights($one('12-24'), true), 'a Thursday holiday with bridges: Wednesday to Saturday nights');
        self::assertSame([['2026-12-23', '2026-12-23', 1, false]], $nights($one('12-24'), false), 'without bridges: Wednesday night only');
        self::assertSame([['2026-12-01', '2026-12-01', 1, false]], $nights($one('12-02'), true), 'a Wednesday holiday: Tuesday night only');
        self::assertSame([['2026-11-13', '2026-11-14', 2, true]], $nights($one('11-15'), true), 'a Sunday holiday falls on a weekend');
        $carnival = [['id' => 'mon', 'rule' => ['easter' => -48], 'names' => ['en' => 'Carnival Monday']], ['id' => 'tue', 'rule' => ['easter' => -47], 'names' => ['en' => 'Carnival Tuesday']]];
        self::assertSame([['2026-02-13', '2026-02-16', 4, false]], $nights($carnival, true), 'Carnival Monday and Tuesday: Friday to Monday nights');
    }

    public function testBrazil2027ProposesCarnivalAndCorpusChristiAsTheSpecSays(): void
    {
        $proposals = HolidayProposals::forYears(self::catalogue()->load('BR'), [2027], true);
        $byName = [];
        foreach ($proposals as $proposal) {
            $byName[$proposal->name('en')] = [Days::key($proposal->firstNight()), Days::key($proposal->lastNight())];
        }

        self::assertSame(['2027-02-05', '2027-02-08'], $byName['Carnival Monday + Carnival Tuesday']);
        self::assertSame(['2027-05-26', '2027-05-29'], $byName['Corpus Christi']);
        $carnival = $proposals[array_search('Carnival Monday + Carnival Tuesday', array_map(static fn (HolidayProposal $p): string => $p->name('en'), $proposals), true)];
        self::assertSame('Segunda-feira de Carnaval + Terça-feira de Carnaval', $carnival->name('pt_BR'));
        self::assertSame(['name' => 'Segunda-feira de Carnaval + Terça-feira de Carnaval', 'start' => '2027-02-05', 'end' => '2027-02-08', 'night_type' => 'holiday', 'min_stay' => null, 'floor' => null], $carnival->toPeriod('pt_BR'));
        self::assertTrue($carnival->ticked());
        $listed = $carnival->markListed([['start' => '2027-02-08', 'end' => '2027-02-10']]);
        self::assertTrue($listed->alreadyListed);
        self::assertFalse($listed->ticked());
        self::assertFalse($carnival->markListed([['start' => '2027-02-09', 'end' => '2027-02-10']])->alreadyListed);
    }

    public function testMalformedListsAreRefused(): void
    {
        $good = ['format' => 'ritmo-revenue-holidays', 'version' => 1, 'country' => 'XX', 'names' => ['en' => 'Test'], 'days_off' => [5, 6], 'bridges' => true, 'holidays' => [['id' => 'h', 'rule' => ['fixed' => '01-01'], 'names' => ['en' => 'H']]]];
        $bad = [
            ['format' => 'other'] + $good,
            ['country' => 'xx'] + $good,
            ['days_off' => [7]] + $good,
            ['holidays' => []] + $good,
            ['holidays' => [['id' => 'h', 'rule' => ['fixed' => '02-30'], 'names' => ['en' => 'H']]]] + $good,
            ['holidays' => [['id' => 'h', 'rule' => ['fixed' => '01-01', 'easter' => 1], 'names' => ['en' => 'H']]]] + $good,
            ['holidays' => [['id' => 'h', 'rule' => ['easter' => 1], 'names' => ['pt_BR' => 'H']]]] + $good,
            ['holidays' => [['id' => 'h', 'rule' => ['easter' => 1], 'names' => ['en' => 'H']], ['id' => 'h', 'rule' => ['easter' => 2], 'names' => ['en' => 'H']]]] + $good,
            ['holidays' => [['id' => 'h', 'rule' => ['easter' => 1], 'kind' => 'local', 'names' => ['en' => 'H']]]] + $good,
        ];
        $refused = 0;
        foreach ($bad as $data) {
            try {
                HolidayList::fromArray($data);
            } catch (InvalidArgumentException) {
                ++$refused;
            }
        }
        self::assertSame(count($bad), $refused);
        $this->expectException(InvalidArgumentException::class);
        self::catalogue()->load('ZZ');
    }

    private static function catalogue(): HolidayCatalogue
    {
        return new HolidayCatalogue(Fixtures::pluginRoot() . '/presets/holidays');
    }
}
