<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Admin\Market;

require_once dirname(__DIR__) . '/Editors/stubs.php';
require_once dirname(__DIR__, 3) . '/Integration/Rest/Editors/stubs.php';

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ritmo\Admin\Market\MarketText;
use Ritmo\Application\Market\MarketRefused;
use Ritmo\Domain\Market\Csv\MarketCsvProblem;
use Ritmo\Domain\Market\Series\ComparableFactor;
use Ritmo\Domain\Market\Series\NightValue;

/**
 * Every problem of a price file and every refusal of the market screen reads as its own plain
 * sentence (never the code, never the fallback), a line names where it is and shows the cell
 * escaped later by the template and cut to 40 characters, and factors and origins read in words.
 */
final class MarketTextTest extends TestCase
{
    public function testEveryProblemCodeHasItsOwnSentence(): void
    {
        $codes = array_values(array_diff((new ReflectionClass(MarketCsvProblem::class))->getConstants(), [MarketCsvProblem::FILE, MarketCsvProblem::ERROR, MarketCsvProblem::WARNING]));
        $codes = array_values(array_filter($codes, 'is_string'));
        self::assertGreaterThan(25, count($codes));
        $fallback = MarketText::what('no_such_code');
        $texts = [];
        foreach ($codes as $code) {
            $text = MarketText::what($code);
            self::assertNotSame($fallback, $text, $code);
            self::assertStringNotContainsString($code, $text, $code . ': the code never shows');
            $texts[] = $text;
        }
        self::assertSame(count($texts), count(array_unique($texts)), 'one sentence per problem');
    }

    public function testEveryRefusalHasItsOwnSentence(): void
    {
        $reasons = array_values((new ReflectionClass(MarketRefused::class))->getConstants());
        $fallback = MarketText::refusal(new MarketRefused('no_such_reason'));
        foreach ($reasons as $reason) {
            $text = MarketText::refusal(new MarketRefused($reason, '', ['code' => MarketCsvProblem::BAD_PRICE, 'date' => '2026-11-13', 'max' => 120]));
            self::assertNotSame($fallback, $text, $reason);
            self::assertStringNotContainsString('_', $text, $reason . ': no code in the sentence');
        }
        self::assertSame('This file was already imported on 2026-11-13. Nothing was imported.', MarketText::refusal(new MarketRefused(MarketRefused::ALREADY_IMPORTED, '', ['date' => '2026-11-13'])));
        self::assertSame('The file is larger than 2048 KB. Nothing was imported.', MarketText::refusal(new MarketRefused(MarketRefused::FILE_ERROR, '', ['code' => MarketCsvProblem::FILE_TOO_LARGE]), 2097152));
    }

    public function testALineSaysWhereAndShowsTheCellCut(): void
    {
        $long = str_repeat('x', 60);

        self::assertSame(
            'Line 7, column price: the price is not a number above zero (such as 480 or 480.50, without thousands separators). ("' . str_repeat('x', 40) . '")',
            MarketText::problem(new MarketCsvProblem(MarketCsvProblem::ERROR, MarketCsvProblem::BAD_PRICE, 7, 'price', $long))
        );
        self::assertSame('Line 9: the line has characters the plugin cannot read.', MarketText::problem(new MarketCsvProblem(MarketCsvProblem::ERROR, MarketCsvProblem::ROW_NOT_UTF8, 9, '', '')));
        self::assertSame('Column email: not imported: the plugin never stores guest data.', MarketText::problem(new MarketCsvProblem(MarketCsvProblem::WARNING, MarketCsvProblem::GUEST_COLUMN, 0, 'email', '')));
    }

    public function testFactorsAndOriginsReadInWords(): void
    {
        self::assertSame('× 1.4 (measured)', MarketText::factor(1.4, ComparableFactor::MEASURED, 1.0, 2.0));
        self::assertSame('× 2 (estimated, capped)', MarketText::factor(2.6, ComparableFactor::ESTIMATED, 1.0, 2.0));
        self::assertSame('× 1.8 (estimated)', MarketText::factor(1.8, ComparableFactor::ESTIMATED, 1.0, 2.0));
        self::assertSame('× 1.395 (not set: default)', MarketText::factor(null, '', 1.395, 2.0));

        $value = static fn (string $origin, ?int $distance = null): NightValue => new NightValue(['50' => 500], $origin, $distance, 4, 40, null, null);
        self::assertSame('measured on stays starting that night', MarketText::origin($value(NightValue::NIGHT)));
        self::assertSame('stays that include the night', MarketText::origin($value(NightValue::CONTAINING)));
        self::assertSame('nearest stays, 2 days away', MarketText::origin($value(NightValue::NEIGHBOUR, 2)));
        self::assertSame('nearest stays, 1 day away', MarketText::origin($value(NightValue::NEIGHBOUR, 1)));
        self::assertSame('typical value for this kind of night', MarketText::origin($value(NightValue::TYPE_TABLE)));
    }
}
