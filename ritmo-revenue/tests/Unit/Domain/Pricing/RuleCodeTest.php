<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Pricing\RuleCode;

/**
 * Codes are stored with every decision and translated by the admin: they must stay distinct,
 * plain and free of any language.
 */
final class RuleCodeTest extends TestCase
{
    public function testRuleCodesAreDistinctSnakeCaseWords(): void
    {
        $codes = (new ReflectionClass(RuleCode::class))->getConstants();

        self::assertGreaterThanOrEqual(18, count($codes));
        self::assertSame(count($codes), count(array_unique($codes)));
        foreach ($codes as $name => $code) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $code);
            self::assertSame(strtoupper($code), $name, 'the constant is named after its code');
        }
        self::assertFalse((new ReflectionClass(RuleCode::class))->isInstantiable());
    }

    public function testPlanIssueCodesAreDistinctSnakeCaseWords(): void
    {
        $codes = array_diff((new ReflectionClass(PlanIssue::class))->getConstants(), [PlanIssue::ERROR, PlanIssue::WARNING]);

        self::assertGreaterThanOrEqual(15, count($codes));
        self::assertSame(count($codes), count(array_unique($codes)));
        foreach ($codes as $name => $code) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $code);
            self::assertSame(strtoupper($code), $name);
        }
        self::assertSame([], array_intersect($codes, (new ReflectionClass(RuleCode::class))->getConstants()), 'an issue code is never a rule code');
    }
}
