<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Write;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Write\ApprovalConfig;
use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Pricing\Cadence\SoldBase;
use Ritmo\Domain\Pricing\Cadence\StepRecord;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Hold\HoldScope;
use Ritmo\Domain\Write\Hold\WaveConfig;
use Ritmo\Domain\Write\ThirdParty\ThirdPartyConfig;
use Ritmo\Domain\Write\VerifyConfig;
use Ritmo\Tests\Support\Fixtures;

/**
 * The configuration sections of the confirmation, record and approval area: neutral defaults,
 * the parity values of the reference, a label and help for every field.
 */
final class ConfigTest extends TestCase
{
    public function testTheDefaultsAreNeutralAndConservative(): void
    {
        self::assertSame(30, VerifyConfig::fromArray([])->deliveryOverdueHours);
        self::assertSame([false, 7], [ThirdPartyConfig::fromArray([])->untilNightPasses, ThirdPartyConfig::fromArray([])->freezeDays], 'a changed night stays frozen 7 days');
        self::assertNull(WaveConfig::fromArray([])->holdHours, 'a wave holds the cuts until acknowledged');
        self::assertSame(6, ApprovalConfig::fromArray([])->maxCalendarAgeHours);
    }

    public function testTheParityFixtureReadsIntoEverySection(): void
    {
        $parity = Fixtures::json('presets/parity-verify.json');

        self::assertSame(30, VerifyConfig::fromArray($parity['verify'])->deliveryOverdueHours);
        $thirdParty = ThirdPartyConfig::fromArray($parity['third_party']);
        self::assertSame([false, 7], [$thirdParty->untilNightPasses, $thirdParty->freezeDays]);
        $waves = WaveConfig::fromArray($parity['waves']);
        self::assertSame([3, 3, null, HoldScope::Property], [$waves->minCancellations, $waves->windowHours, $waves->holdHours, $waves->scope]);
        self::assertSame(6, ApprovalConfig::fromArray($parity['approval'])->maxCalendarAgeHours);
    }

    public function testEveryFieldIsDescribed(): void
    {
        $fields = [...VerifyConfig::fields(), ...ThirdPartyConfig::fields(), ...WaveConfig::fields(), ...ApprovalConfig::fields()];
        $keys = array_map(static fn (Field $f): string => $f->key, $fields);

        self::assertSame(count($keys), count(array_unique($keys)));
        foreach ($fields as $field) {
            self::assertNotSame('', $field->help, $field->key);
        }
    }

    public function testOutOfRangeValuesAreRefused(): void
    {
        $this->expectException(InvalidConfig::class);

        VerifyConfig::fromArray(['delivery_overdue_hours' => 0]);
    }

    public function testTheOverdueLimitReachesTheChannelCheck(): void
    {
        $report = (new VerifyConfig(1))->channelArrival()->check(
            ['s' => new StepRecord('Deluxe', Days::date('2027-03-10'), 1000.0, 900.0, StepDirection::Down, 'behind', null, SoldBase::Room, null, new DateTimeImmutable('2027-03-03T10:00:00Z'))],
            [],
            [],
            ['Deluxe' => 1000.0],
            new DateTimeImmutable('2027-03-03T11:30:00Z'),
        );

        self::assertSame(['s'], $report->overdue);
    }
}
