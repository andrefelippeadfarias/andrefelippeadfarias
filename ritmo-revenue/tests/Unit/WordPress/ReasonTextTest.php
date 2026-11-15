<?php

declare(strict_types=1);

namespace Ritmo\Admin {
    if (!function_exists(__NAMESPACE__ . '\__')) {
        /**
         * Stand-in for the WordPress translation function: the English text as it is.
         */
        function __(string $text, string $domain = 'default'): string
        {
            return $domain === 'ritmo-revenue' ? $text : '[wrong text domain] ' . $text;
        }
    }
}

namespace Ritmo\Tests\Unit\WordPress {
    require_once __DIR__ . '/../Admin/Screens/WpStubs.php';

    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use Ritmo\Admin\ReasonText;
    use Ritmo\Admin\View;
    use Ritmo\Application\ShadowRun\PlanItemStatus;
    use Ritmo\Domain\Pricing\RuleCode;
    use Ritmo\Tests\Unit\Application\ShadowRunWorld;

    final class ReasonTextTest extends TestCase
    {
        public function testATraceBecomesSentencesInTheOrderTheRulesApplied(): void
        {
            $trace = [
                ['code' => RuleCode::NIGHT, 'params' => ['night_type' => 'holiday', 'days_out' => 12, 'period' => 'Carnival']],
                ['code' => RuleCode::PACE_STATUS, 'params' => ['status' => 'very_behind', 'sold' => 0, 'units' => 4, 'basis' => 'room', 'sold_percent' => 0.0, 'expected_percent' => 37.5]],
                ['code' => RuleCode::MARKET_TARGET, 'params' => ['percentile' => 30.0, 'public' => 444.5, 'sent' => 444.5]],
                ['code' => RuleCode::WEAK_ANCHOR, 'params' => ['n' => 3, 'age_days' => 20]],
                ['code' => RuleCode::STEP, 'params' => ['from' => 640.0, 'to' => 544.0, 'max_percent' => 15.0]],
                ['code' => RuleCode::HELD_AT_FLOOR, 'params' => ['floor' => 560.0, 'label' => 'owner_floor']],
                ['code' => RuleCode::PACE_STEP, 'params' => ['direction' => 'down', 'min_price' => 560.0, 'max_price' => 560.0]],
                ['code' => 'a_rule_of_a_later_milestone', 'params' => []],
            ];

            self::assertSame([
                'Holiday night, 12 days out. Period: Carnival.',
                'Pace very behind: 0 of 4 units sold (0% against 37.5% expected).',
                'Target: the market price at percentile 30, 444.5 as guests see it (444.5 in the plan sent to the pricing system).',
                'Weak market data (3 prices, 20 days old): half a step at most.',
                'One step from 640 to 544 (at most 15%).',
                'Held at the hard floor of 560 (owner floor).',
                'Proposed step down: floor 560, ceiling 560.',
            ], ReasonText::sentences($trace));
        }

        public function testEveryRuleOfTheEngineHasASentence(): void
        {
            $codes = array_values((new ReflectionClass(RuleCode::class))->getConstants());
            $skips = [RuleCode::PAST_NIGHT, RuleCode::SOLD_OUT_PENDING_M2, RuleCode::NO_MARKET_ANCHOR];
            foreach (array_diff($codes, $skips) as $code) {
                self::assertCount(1, ReasonText::sentences([['code' => $code, 'params' => []]]), $code);
            }
            self::assertSame(['Weekday night, days out not known.'], ReasonText::sentences([['code' => RuleCode::NIGHT, 'params' => []]]));
        }

        /** Regression: amounts in the currency of the property, numbers in the person's format, one day in the singular. */
        public function testAmountsAreMoneyAndDaysAgreeWithTheirNumber(): void
        {
            $text = ReasonText::describe([
                ['code' => RuleCode::NIGHT, 'params' => ['night_type' => 'weekend', 'days_out' => 1]],
                ['code' => RuleCode::MARKET_TARGET, 'params' => ['percentile' => 30.0, 'public' => 828.8, 'sent' => 828.8]],
                ['code' => RuleCode::STEP, 'params' => ['from' => 1350.0, 'to' => 1248.75, 'max_percent' => 7.5]],
            ], 'BRL');

            self::assertStringContainsString('Weekend night, 1 day out.', $text);
            self::assertSame('Holiday night, tonight.', ReasonText::describe([['code' => RuleCode::NIGHT, 'params' => ['night_type' => 'holiday', 'days_out' => 0]]]));
            self::assertStringContainsString("R$\u{00A0}828.80 as guests see it", $text);
            self::assertStringContainsString("One step from R$\u{00A0}1,350 to R$\u{00A0}1,248.75 (at most 7.5%).", $text);
            self::assertDoesNotMatchRegularExpression('/\b\d{4,}\b/', $text, 'no bare amount');
        }

        /** Regression: every floor the engine names reads in words, never as its code. */
        public function testEveryFloorLabelOfTheEngineReadsInWords(): void
        {
            $labels = ReasonText::floorLabels();
            self::assertContains('order_floor', $labels);
            $texts = [];
            foreach ($labels as $label) {
                $text = ReasonText::describe([['code' => RuleCode::HELD_AT_FLOOR, 'params' => ['floor' => 500.0, 'label' => $label]]], 'BRL');
                self::assertStringNotContainsString($label, $text);
                self::assertStringNotContainsString('_', $text);
                $texts[] = $text;
            }
            self::assertSame(count($texts), count(array_unique($texts)), 'one name per floor');
            self::assertStringNotContainsString('some_new_floor', ReasonText::describe([['code' => RuleCode::HELD_AT_FLOOR, 'params' => ['floor' => 500.0, 'label' => 'some_new_floor']]]));
        }

        public function testTheTracesOfARealShadowRunAreExplained(): void
        {
            $world = new ShadowRunWorld();
            $outcome = $world->service()->run(ShadowRunWorld::PROPERTY);

            foreach ($world->planItems->forRun((int) $outcome->runId) as $item) {
                if ($item['status'] === PlanItemStatus::WOULD_WRITE) {
                    $text = ReasonText::describe($item['details']['rule_trace']);
                    self::assertStringStartsWith('Weekday night', $text);
                    self::assertStringContainsString('Proposed step', $text);
                    self::assertStringNotContainsString('?', $text);
                }
            }
        }

        public function testATemplateIsNamedByAPlainPath(): void
        {
            $this->expectException(InvalidArgumentException::class);
            View::render('../uninstall', []);
        }
    }
}
