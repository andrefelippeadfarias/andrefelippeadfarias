<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Config;

use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\Golden;

/**
 * The lookups of the rule set against the reference engine, computed from the parity preset.
 */
final class RuleSetGoldenTest extends TestCase
{
    public function testMaxStepPercentMatchesPmax(): void
    {
        $rules = Fixtures::parityRules();
        $cases = Golden::cases('pace.pmax');

        self::assertGreaterThanOrEqual(18, count($cases));
        foreach ($cases as $case) {
            self::assertSame((float) $case['expected'], $rules->maxStepPercent($case['input']['dias']), 'D' . $case['input']['dias']);
        }
    }

    public function testTargetPercentileMatchesPercentilAlvo(): void
    {
        $rules = Fixtures::parityRules();
        $cases = Golden::cases('pace.percentil_alvo');

        self::assertGreaterThanOrEqual(56, count($cases));
        foreach ($cases as $case) {
            $in = $case['input'];
            self::assertSame(
                (float) $case['expected'],
                $rules->targetPercentile(Golden::tier($in['pousada']), Golden::paceStatus($in['status']), $in['dias']),
                json_encode($in)
            );
        }
    }

    public function testThePropertiesOfTheParityPresetUseTheTiersOfTheManifest(): void
    {
        $preset = Fixtures::parityPreset();
        foreach (Golden::manifest()['vocabulary']['property'] as $engineName => $propertyId) {
            self::assertSame(Golden::tier($engineName), $preset->property($propertyId)?->tier, $engineName);
        }
    }
}
