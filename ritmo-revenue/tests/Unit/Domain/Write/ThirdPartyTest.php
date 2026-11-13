<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Write;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\ThirdParty\DetectedFreeze;
use Ritmo\Domain\Write\ThirdParty\ManualChangeDetector;
use Ritmo\Domain\Write\ThirdParty\Observation;
use Ritmo\Domain\Write\ThirdParty\ThirdPartyChange;
use Ritmo\Domain\Write\ThirdParty\ThirdPartyConfig;
use Ritmo\Tests\Unit\Domain\Plan\PlanSample;

/**
 * A night changed by someone else is found by comparing states, frozen, and never written over
 * while frozen (task rule 9(l); docs/VERIFY.md, section 5).
 */
final class ThirdPartyTest extends TestCase
{
    private const TODAY = '2027-03-03';

    public function testADifferenceFromTheLastObservationIsAThirdPartyChange(): void
    {
        $changes = self::detect(
            [self::pricing('2027-03-10', [ChangeField::FLOOR_PCT => -20], '2027-03-02T10:00:00Z')],
            [self::pricing('2027-03-10', [ChangeField::FLOOR_PCT => -5, ChangeField::CEILING_PCT => -5])],
        );

        self::assertCount(1, $changes);
        self::assertSame([ChangeField::CEILING_PCT => [null, -5], ChangeField::FLOOR_PCT => [-20, -5]], $changes[0]->fields);
        self::assertSame('2027-03-02T10:00:00+00:00', $changes[0]->previousAt->format(DATE_ATOM));
    }

    public function testThePluginsOwnWriteIsNotAChange(): void
    {
        $written = new Observation(Provider::Pricing, 'Deluxe', Days::date('2027-03-10'), new NightFields([ChangeField::FLOOR_PCT => -29]), new DateTimeImmutable('2027-03-02T10:05:00Z'), Observation::WRITTEN);

        self::assertSame([], self::detect([$written], [self::pricing('2027-03-10', [ChangeField::FLOOR_PCT => -29])]));
    }

    public function testWhatIsNotAThirdPartyChange(): void
    {
        $previous = [
            self::pricing('2027-03-10', [ChangeField::FLOOR_PCT => -20], '2027-03-02T10:00:00Z'),
            self::pricing('2027-03-11', [ChangeField::FLOOR_PCT => -20, ChangeField::EXPIRY => '2027-03-01'], '2027-03-02T10:00:00Z'),
            self::pricing('2027-03-02', [ChangeField::FLOOR_PCT => -20], '2027-03-01T10:00:00Z'),
            self::pms('2027-03-12', [ChangeField::PMS_AVAILABLE => 4, ChangeField::PMS_MIN_STAY => 2]),
            self::pms('2027-03-13', [ChangeField::PMS_MIN_STAY => 2]),
        ];
        $current = [
            self::pricing('2027-03-10', [ChangeField::FLOOR_PCT => -25]),
            self::pricing('2027-03-11', []),
            self::pricing('2027-03-02', [ChangeField::FLOOR_PCT => -40]),
            self::pms('2027-03-12', [ChangeField::PMS_AVAILABLE => 2, ChangeField::PMS_MIN_STAY => 2]),
            self::pms('2027-03-13', [ChangeField::PMS_MIN_STAY => 3]),
            self::pricing('2027-03-14', [ChangeField::FLOOR_PCT => -20]),
        ];
        $changes = (new ManualChangeDetector())->detect(
            self::byKey($previous),
            $current,
            ['pricing|Deluxe|2027-03-10' => true],
            ['pms|Deluxe|2027-03-13' => new NightFields([ChangeField::PMS_MIN_STAY => 3])],
            Days::date(self::TODAY),
        );

        self::assertSame([], $changes, 'unresolved write, expiry, past night, availability, pushed stay, baseline');
    }

    public function testAFreezeCoversExactlyTheChangedNightsOfARoom(): void
    {
        $changes = self::detect(
            [self::pricing('2027-03-10', [ChangeField::FLOOR_PCT => -20]), self::pricing('2027-03-20', [ChangeField::MIN_STAY => 2]), self::pricing('2027-03-11', [ChangeField::FLOOR_PCT => -20])],
            [self::pricing('2027-03-10', [ChangeField::FLOOR_PCT => -10]), self::pricing('2027-03-20', [ChangeField::MIN_STAY => 1]), self::pricing('2027-03-11', [ChangeField::FLOOR_PCT => -10])],
        );
        $now = new DateTimeImmutable('2027-03-03T12:00:00Z');
        $freezes = DetectedFreeze::fromChanges($changes, $now, new ThirdPartyConfig(true));

        self::assertCount(1, $freezes);
        self::assertNull($freezes[0]->frozenUntil, 'until released or the night passes');
        self::assertSame('2027-03-10T12:00:00+00:00', DetectedFreeze::fromChanges($changes, $now, new ThirdPartyConfig())[0]->frozenUntil?->format(DATE_ATOM), 'seven days by default');
        self::assertSame([ChangeField::FLOOR_PCT, ChangeField::MIN_STAY], $freezes[0]->fields);
        $orders = $freezes[0]->toOrders($now, Days::date(self::TODAY));
        self::assertSame([['2027-03-10', '2027-03-11'], ['2027-03-20', '2027-03-20']], array_map(static fn ($o): array => [Days::key($o->from), Days::key($o->to)], $orders));
        $book = new OrderBook($orders);
        self::assertNotNull($book->manualFreeze('Deluxe', Days::date('2027-03-20')));
        self::assertNull($book->manualFreeze('Deluxe', Days::date('2027-03-15')), 'a night between is not frozen');
    }

    public function testAFreezeEndsWhenReleasedOrAfterItsDays(): void
    {
        $at = new DateTimeImmutable('2027-03-03T12:00:00Z');
        $timed = DetectedFreeze::fromChanges(self::detect([self::pricing('2027-04-20', [ChangeField::FLOOR_PCT => -20])], [self::pricing('2027-04-20', [ChangeField::FLOOR_PCT => -10])]), $at, new ThirdPartyConfig(false, 7))[0];
        $released = new DetectedFreeze('Deluxe', [Days::date('2027-04-20')], $at, null, [], 4, $at->modify('+1 hour'));

        self::assertCount(1, $timed->toOrders($at->modify('+6 days'), Days::date('2027-03-09')), 'a night far ahead is frozen too (C5)');
        self::assertSame([], $timed->toOrders($at->modify('+7 days'), Days::date('2027-03-10')));
        self::assertSame([], $released->activeNights($at->modify('+2 hours'), Days::date(self::TODAY)));
        self::assertSame([], $timed->activeNights($at, Days::date('2027-04-21')), 'the night passed');
    }

    public function testTheValidatorRefusesItemsAndDeletionsOnAFrozenNight(): void
    {
        $at = new DateTimeImmutable(PlanSample::NOW);
        $freeze = new DetectedFreeze('Deluxe', [Days::date('2027-03-10')], $at, null, [ChangeField::FLOOR_PCT]);
        $context = PlanSample::context(orders: new OrderBook($freeze->toOrders($at, Days::date(PlanSample::TODAY))));

        self::assertSame([PlanIssue::NIGHT_FROZEN], PlanSample::validate([PlanSample::item()], $context)->errorCodes());
        self::assertSame([PlanIssue::NIGHT_FROZEN], PlanSample::validate([], $context, null, [new PlanDeletion('Deluxe', Days::date('2027-03-10'), 'release_ceiling')])->errorCodes());
    }

    public function testTheConfigurationHasNeutralDefaults(): void
    {
        $config = ThirdPartyConfig::fromArray([]);

        self::assertSame([false, 7], [$config->untilNightPasses, $config->freezeDays]);
        $this->expectException(InvalidConfig::class);
        ThirdPartyConfig::fromArray(['freeze_days' => 0]);
    }

    /**
     * @param list<Observation> $previous
     * @param list<Observation> $current
     *
     * @return list<ThirdPartyChange>
     */
    private static function detect(array $previous, array $current): array
    {
        return (new ManualChangeDetector())->detect(self::byKey($previous), $current, [], [], Days::date(self::TODAY));
    }

    /**
     * @param list<Observation> $observations
     *
     * @return array<string, Observation>
     */
    private static function byKey(array $observations): array
    {
        $byKey = [];
        foreach ($observations as $observation) {
            $byKey[$observation->key()] = $observation;
        }

        return $byKey;
    }

    /**
     * @param array<string, int|string> $fields
     */
    private static function pricing(string $night, array $fields, string $at = '2027-03-03T11:00:00Z'): Observation
    {
        return new Observation(Provider::Pricing, 'Deluxe', Days::date($night), new NightFields($fields), new DateTimeImmutable($at));
    }

    /**
     * @param array<string, int|string> $fields
     */
    private static function pms(string $night, array $fields): Observation
    {
        return new Observation(Provider::Pms, 'Deluxe', Days::date($night), new NightFields($fields), new DateTimeImmutable('2027-03-02T10:00:00Z'));
    }
}
