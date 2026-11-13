<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Report;

use Ritmo\Tests\Support\ParityNames;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Report\Config\ReportsConfig;
use Ritmo\Domain\Report\Config\ReportsFields;
use Ritmo\Domain\Report\Targets\Scenario;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;

final class ReportsConfigTest extends TestCase
{
    public function testTheNeutralDefaultsShowEverythingAndForecastNothingSpecial(): void
    {
        $config = ReportsConfig::neutral();
        self::assertSame(14, $config->mapNights);
        self::assertSame([], $config->groups);
        self::assertSame(15, $config->review->intervalDays);
        self::assertSame(1.2, $config->review->aheadRatio);
        self::assertSame(0.8, $config->review->behindRatio);
        self::assertSame(5.0, $config->review->earlyMarketPercent);
        self::assertSame([], $config->review->priceTags);
        self::assertNull($config->weeklyStart);
        self::assertSame(1.0, $config->forecast->growth(Scenario::Goal));
        self::assertSame(1.0, $config->forecast->index(Scenario::Base, 'any'));
        self::assertNull($config->forecast->seasonMinimum(12, 'any'));
    }

    public function testTheParityFixtureReads(): void
    {
        $config = ReportsConfig::fromArray(Fixtures::json('presets/parity-reports.json')['reports']);
        self::assertSame('Rooms with a bathtub', $config->groups[0]->label);
        self::assertSame(['tub'], $config->review->priceTags);
        self::assertSame('2026-09-07', Days::key($config->weeklyStart));
        self::assertSame(1.35, $config->forecast->index(Scenario::Base, ParityNames::property('first')));
        self::assertTrue($config->forecast->isHigh(1));
        self::assertSame(150000.0, $config->forecast->seasonMinimum(11, ParityNames::property('second')));
    }

    public function testEveryProblemIsReportedWithItsPath(): void
    {
        try {
            ReportsConfig::fromArray([
                'occupancy' => ['map_nights' => 0, 'groups' => [['label' => '', 'tags' => []]]],
                'review' => ['behind_ratio' => 2],
                'forecast' => ['scenarios' => ['base' => ['growth' => 9]], 'high_months' => [13]],
                'unknown' => 1,
            ]);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            $paths = array_map(static fn (ValidationError $err): string => $err->path, $e->errors());
            foreach (['reports.occupancy.map_nights', 'reports.review.behind_ratio', 'reports.forecast.scenarios.base.growth', 'reports.forecast.high_months.0', 'reports.occupancy.groups.0.tags'] as $path) {
                self::assertContains($path, $paths);
            }
        }
    }

    public function testEveryFieldIsUnderTheSectionAndDescribed(): void
    {
        foreach (ReportsFields::all() as $field) {
            self::assertStringStartsWith(ReportsFields::ROOT, $field->key);
            self::assertNotSame('', $field->label);
        }
    }
}
