<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Pricing\RuleCode;
use Ritmo\Tests\Support\ContractsDoc;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\SourceScan;

/**
 * docs/ENGINE.md is what the application and the panel are written against: its signature
 * reference must be the code's, and its guide must name every class and every code.
 */
final class EngineDocTest extends TestCase
{
    private static function guide(): string
    {
        $text = (string) file_get_contents(ContractsDoc::path(ContractsDoc::ENGINE));

        return substr($text, 0, (int) strpos($text, ContractsDoc::BEGIN));
    }

    public function testTheSignatureReferenceIsCurrent(): void
    {
        self::assertSame(
            ContractsDoc::render(ContractsDoc::ENGINE),
            ContractsDoc::current(ContractsDoc::ENGINE),
            'docs/ENGINE.md is stale: run php bin/sync-generated.php'
        );
        self::assertGreaterThan(200, substr_count(ContractsDoc::current(ContractsDoc::ENGINE), "\n"));
    }

    public function testTheGuideNamesEveryEngineClass(): void
    {
        $guide = self::guide();
        $classes = [];
        foreach (['Calendar', 'Market', 'Pricing', 'Plan', 'Report'] as $module) {
            foreach (SourceScan::phpFiles(Fixtures::pluginRoot() . '/src/Domain/' . $module) as $file) {
                $classes[] = basename($file, '.php');
            }
        }

        self::assertGreaterThan(30, count($classes));
        foreach ($classes as $class) {
            self::assertSame(1, preg_match('/\\b' . $class . '\\b/', $guide), "docs/ENGINE.md does not mention $class");
        }
    }

    public function testTheGuideListsEveryCode(): void
    {
        $guide = self::guide();
        $codes = array_merge(
            (new ReflectionClass(RuleCode::class))->getConstants(),
            array_diff((new ReflectionClass(PlanIssue::class))->getConstants(), [PlanIssue::ERROR, PlanIssue::WARNING]),
            [Floors::LISTING_MIN, Floors::REFERENCE_MIN_FRACTION, Floors::OWNER, Floors::QUALITY, Floors::HOLIDAY],
        );

        self::assertGreaterThan(35, count($codes));
        foreach ($codes as $code) {
            self::assertTrue(str_contains($guide, '`' . $code . '`'), "docs/ENGINE.md does not list the code $code");
        }
    }
}
