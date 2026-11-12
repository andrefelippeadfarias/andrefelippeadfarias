<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\PresetLoader;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\PercentRounding;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Pricing\NightContext;
use Ritmo\Domain\Pricing\PaceEngine;
use Ritmo\Domain\Pricing\RuleCode;
use Ritmo\Domain\Support\FixedClock;
use Ritmo\Tests\Support\Fixtures;

/**
 * "The price of today never goes up" against the rounding of the floor, on every room of the
 * parity preset: a step down that the pricing system would apply above tonight's price must
 * never come out of the engine, and the validator must refuse one that does.
 *
 * The rounding bites when one whole percentage of the base is more than the tolerance of the
 * price: with a tolerance of 1%, on every room whose base passes its price.
 */
final class TonightRoundingTest extends TestCase
{
    private const NOW = '2026-11-17T15:00:00+00:00';

    /**
     * @return array<string, array{float}>
     */
    public static function tolerances(): array
    {
        return [
            'tolerance 1%' => [0.01],
            'tolerance of the parity preset' => [Fixtures::parityRules()->tolerance()],
            'default tolerance' => [(float) Schema::ruleSet()->defaults()['pace']['tolerance']],
        ];
    }

    /**
     * @dataProvider tolerances
     */
    public function testNoStepOfTonightIsAppliedAboveItsPrice(float $tolerance): void
    {
        $data = json_decode((string) file_get_contents(Fixtures::path('presets/parity.json')), true, 512, JSON_THROW_ON_ERROR);
        $data['rules']['pace']['tolerance'] = $tolerance;
        $preset = (new PresetLoader())->fromArray($data);
        $clock = new FixedClock(self::NOW);
        $engine = new PaceEngine($preset->rules, $clock);
        $validator = new Validator($preset->rules, $clock);
        $rounding = new PercentRounding();

        $steps = 0;
        $limited = 0;
        foreach ($preset->properties as $property) {
            $today = $clock->today($property->timezone);
            foreach ($preset->roomTypesOf($property->id) as $room) {
                $anchor = new MarketAnchor($today, 0.38 * $room->base, 0.7 * $room->base, 0.95 * $room->base, 1.2 * $room->base, 1.6 * $room->base, 12, $today, AnchorOrigin::Measured);
                for ($price = $room->min; $price <= 1.2 * $room->base; $price += 1.0) {
                    $decision = $engine->decide(new NightContext($property, $room, $today, $price, 0, $room->units, $anchor, $room->min, null));
                    if ($decision->applied(RuleCode::TODAY_NEVER_RISES) && $decision->isStep()) {
                        ++$limited;
                    }
                    if (!$decision->isStep() || $decision->minPrice === null) {
                        continue;
                    }
                    ++$steps;
                    $item = PlanItem::fromDecision($decision, new NightState($price, $room->min, null));
                    $applied = $rounding->convert($item, $room)->minAmount(2);
                    self::assertLessThanOrEqual($price, $applied, sprintf('%s at %.2f: floor %.2f applied as %.2f', $room->code, $price, $decision->minPrice, $applied));

                    $result = $validator->validate(new Plan($property->id, $today, $clock->now(), [$item]), $property, $preset->roomTypesOf($property->id));
                    self::assertNotContains(PlanIssue::RAISE_TODAY, $result->errorCodes(), sprintf('%s at %.2f', $room->code, $price));
                }
            }
        }

        self::assertGreaterThan(0, $steps, 'tonight does step down');
        if ($tolerance <= 0.01) {
            self::assertGreaterThan(0, $limited, 'with a tolerance of 1% some steps had to go down to a whole percentage');
        }
    }

    public function testTheValidatorRefusesAFloorOfTonightThatRoundsAboveThePrice(): void
    {
        $preset = Fixtures::parityPreset();
        $clock = new FixedClock(self::NOW);
        $validator = new Validator($preset->rules, $clock);

        $refused = 0;
        foreach ($preset->properties as $property) {
            $today = $clock->today($property->timezone);
            foreach ($preset->roomTypesOf($property->id) as $room) {
                // A floor one unit under the price, where the next whole percentage passes it.
                $percent = (int) floor(($room->min * 1.2 / $room->base - 1) * 100) + 1;
                $applied = $room->base * (1 + $percent / 100);
                $price = $applied - 0.5;
                $item = new PlanItem($room->code, $today, $price - 1.0, null, null, RuleCode::PACE_STEP, [], new NightState($price, $room->min, null));
                if ((new PercentRounding())->convert($item, $room)->minAmount(2) <= $price) {
                    continue;
                }
                $result = $validator->validate(new Plan($property->id, $today, $clock->now(), [$item]), $property, $preset->roomTypesOf($property->id));
                self::assertContains(PlanIssue::RAISE_TODAY, $result->errorCodes(), $room->code);
                self::assertNull($result->plan);
                ++$refused;
            }
        }

        self::assertGreaterThan(0, $refused);
    }
}
