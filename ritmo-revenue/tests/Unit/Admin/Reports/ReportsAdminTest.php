<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Admin\Reports;

require_once dirname(__DIR__, 3) . '/Integration/Rest/Reports/stubs.php';

use PHPUnit\Framework\TestCase;
use Ritmo\Admin\Components\CsvWriter;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Reports\BarChart;
use Ritmo\Admin\Reports\Cell;
use Ritmo\Admin\Reports\ReportsActions;
use Ritmo\Admin\Reports\ReportTable;
use Ritmo\Admin\Reports\RunLineText;
use Ritmo\Domain\Report\RunLines\RunLine;
use Ritmo\Domain\Report\RunLines\RunLineCode;
use Ritmo\Tests\Support\Fixtures;

/**
 * The reports area's texts, CSV, chart and actions, outside WordPress.
 */
final class ReportsAdminTest extends TestCase
{
    public function testEveryRunLineCodeHasASentenceWithItsNumbersFilledIn(): void
    {
        foreach (RunLineCode::cases() as $code) {
            $params = [];
            foreach ($code->params() as $name => $type) {
                $params[$name] = $type === 'int' ? 7 : 'pricing_first';
            }
            $text = RunLineText::of(RunLine::of($code, $params));
            self::assertNotSame('', $text, $code->value);
            self::assertStringNotContainsString('%', $text, $code->value . ': every placeholder filled');
            self::assertStringNotContainsString('[wrong text domain]', $text);
            self::assertStringNotContainsString('pricing_first', $text, $code->value . ': a code is said in words');
            if ($params !== [] && in_array('int', $code->params(), true)) {
                self::assertStringContainsString('7', $text, $code->value);
            }
        }
        self::assertSame('1 room night sold beyond the units.', RunLineText::of(RunLine::of(RunLineCode::Overbooked, ['count' => 1])));
    }

    public function testATableExportsWithItsHeaderTheCurrencyAndTheFormulaGuard(): void
    {
        $table = new ReportTable('t', 'Caption', [['Room', Cell::Text], ['Sold', Cell::Count], ['Revenue', Cell::Money], ['Occupancy', Cell::Fraction], ['Change', Cell::Change]], [
            ['=HYPERLINK("x")', 3, 1250.5, 0.2264, -0.05],
            ['+1', 0, -10.0, null, null],
        ]);
        $stream = fopen('php://memory', 'w+b');
        CsvWriter::write($stream, $table->csvHeader(), $table->csvRows('BRL'));
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        self::assertSame(
            "\"Room\",\"Sold\",\"Revenue\",\"Occupancy\",\"Change\",\"Currency\"\n\"'=HYPERLINK(\"\"x\"\")\",3,1250.5,22.64,-5,\"BRL\"\n\"'+1\",0,-10,,,\"BRL\"\n",
            $csv
        );
        self::assertSame(['=HYPERLINK("x")', '3', "R$\u{00A0}1,250.50", '23%', "\u{2212}5.0%"], $table->formatted($table->rows[0], 'BRL'));
        self::assertSame('—', Cell::Money->format(null, 'BRL'));
    }

    public function testTheChartGeometryKeepsBarsWithinTheDrawing(): void
    {
        $chart = BarChart::of([['label' => '2026-11-09', 'value' => 0.71, 'text' => '71%'], ['label' => '2026-11-16', 'value' => 2.0, 'text' => '200%']]);
        self::assertSame(60, $chart['height']);
        self::assertSame([150, 0, 305, 16], [$chart['bars'][0]['x'], $chart['bars'][0]['y'], $chart['bars'][0]['width'], $chart['bars'][0]['textY']]);
        self::assertSame(430, $chart['bars'][1]['width'], 'a value above 1 is drawn full width');
        self::assertLessThanOrEqual(BarChart::WIDTH, $chart['bars'][1]['valueX'] + 40);
        self::assertNull(BarChart::of([]));
    }

    public function testTypedNumbersInEitherNotation(): void
    {
        self::assertSame(1234.56, ReportsActions::number('1.234,56'));
        self::assertSame(1234.56, ReportsActions::number('1,234.56'));
        self::assertSame(12.5, ReportsActions::number('12,5'));
        self::assertSame(45.0, ReportsActions::number('45'));
        self::assertFalse(ReportsActions::number('=1+1'));
        self::assertFalse(ReportsActions::number('-5'));
    }

    public function testActionsCarryTheirCapabilities(): void
    {
        $caps = [];
        foreach (ReportsActions::actions() as $action) {
            self::assertInstanceOf(FormAction::class, $action);
            $caps[$action->action] = $action->capability;
        }
        self::assertSame(['ritmo_export_report' => 'ritmo_view', 'ritmo_review_snapshot' => 'ritmo_manage_rules', 'ritmo_targets_save' => 'ritmo_manage_rules'], $caps);
    }

    public function testTheTemplatesEscapeEveryOutput(): void
    {
        foreach (glob(Fixtures::pluginRoot() . '/templates/admin/reports/*.php') ?: [] as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all('/<\?php\s+echo\s+([^;]+);/', $code, $echoes);
            foreach ($echoes[1] as $expression) {
                self::assertMatchesRegularExpression('/^(esc_html|esc_attr|esc_url|wp_kses)\(/', trim($expression), basename($file) . ': ' . $expression);
            }
            self::assertStringContainsString("defined('ABSPATH') || exit;", $code, basename($file));
        }
    }
}
