<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Config;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\RuleSetValidator;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Market\Series\LadderSeriesCheck;
use Ritmo\Tests\Support\Fixtures;

/**
 * The sections of milestone M4 in the single schema: market, reports and the written stay are
 * validated with the rest, and a sales ladder rule may only cite a series the market builds.
 */
final class RuleSetValidatorSectionsTest extends TestCase
{
    public function testTheNeutralDefaultsKeepTheStayWritesOffAndPassTheValidator(): void
    {
        $defaults = Schema::ruleSet()->defaults();

        self::assertFalse($defaults['stay']['write']['enabled'], 'writing stay restrictions starts off');
        self::assertSame([], (new RuleSetValidator())->validate($defaults));
    }

    public function testALadderRuleCitingASeriesTheMarketDoesNotBuildIsRefused(): void
    {
        $rules = Fixtures::json('presets/parity.json')['rules'];
        $rules['ladder']['strategies'][0]['rules'][0]['opening']['series'] = 'nowhere';

        $codes = array_map(static fn ($e): string => $e->path . '|' . $e->code, (new RuleSetValidator())->validate($rules));

        self::assertContains('ladder.strategies.0.rules.0.opening.series|' . LadderSeriesCheck::SERIES_UNKNOWN, $codes);
    }

    public function testAPercentileTheMarketDoesNotKeepIsRefused(): void
    {
        $rules = Fixtures::json('presets/parity.json')['rules'];
        $rules['ladder']['strategies'][0]['rules'][0]['opening']['percentile'] = 33.0;

        $codes = array_map(static fn ($e): string => $e->code, (new RuleSetValidator())->validate($rules));

        self::assertContains(LadderSeriesCheck::PERCENTILE_NOT_MEASURED, $codes, 'the market keeps 10, 25, 40, 50, 65, 75 and 90');
    }

    public function testAWrongValueOfTheWrittenStayIsReportedAtItsPath(): void
    {
        $rules = Schema::ruleSet()->defaults();
        $rules['stay']['write']['horizon_days'] = 0;

        $paths = array_map(static fn ($e): string => $e->path, (new RuleSetValidator())->validate($rules));

        self::assertContains('stay.write.horizon_days', $paths);
    }
}
