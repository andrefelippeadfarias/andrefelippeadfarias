<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Admin\Screens;

require_once __DIR__ . '/WpStubs.php';

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Admin\Components\ConfirmTyped;
use Ritmo\Admin\Components\CsvWriter;
use Ritmo\Admin\Components\ErrorText;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\Money;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Admin\Components\Paging;
use Ritmo\Admin\Components\Percent;
use Ritmo\Admin\Components\PriceText;
use Ritmo\Admin\Components\PrivacyGuard;
use Ritmo\Admin\Components\Ratio;
use Ritmo\Admin\Components\UserFacing;
use Ritmo\Domain\Support\Days;
use RuntimeException;

/**
 * The formatting and guard components of the screens (admin spec 4.2, 4.6-4.8).
 */
final class ComponentsTest extends TestCase
{
    public function testMoneyShowsTheSymbolANoBreakSpaceAndDecimalsOnlyWhenNeeded(): void
    {
        self::assertSame("R$\u{00A0}480", Money::format(480.0, 'BRL'));
        self::assertSame("R$\u{00A0}1,350", Money::format(1350.004, 'BRL'));
        self::assertSame("R$\u{00A0}542.50", Money::format(542.5, 'brl'));
        self::assertSame("€\u{00A0}99.99", Money::format(99.99, 'EUR'));
        self::assertSame("CHF\u{00A0}10", Money::format(10.0, 'CHF'), 'an unknown code is shown as the code');
        self::assertSame("\u{2212}US$\u{00A0}5", Money::format(-5.0, 'USD'));
    }

    public function testPercentChangeAndPercentOfTheBaseInWords(): void
    {
        self::assertSame('+4.2%', Percent::change(500.0, 521.0));
        self::assertSame("\u{2212}5.0%", Percent::change(400.0, 380.0));
        self::assertSame('0.0%', Percent::change(400.0, 400.0));
        self::assertSame('new', Percent::change(0.0, 380.0));
        self::assertSame('20% below the base', Percent::ofBase(-20));
        self::assertSame('15% above the base', Percent::ofBase(15));
        self::assertSame('at the base', Percent::ofBase(0));
        self::assertSame('62%', Percent::share(62.4));
        self::assertSame('7.1%', Percent::share(7.14));
        self::assertSame('—', Percent::share(null));
    }

    public function testPriceTextPutsMoneyFirstAndThePercentInWords(): void
    {
        self::assertSame(['text' => "R$\u{00A0}480", 'note' => "20% below the base of R$\u{00A0}600", 'sr' => "R$\u{00A0}480 (20% below the base of R$\u{00A0}600)"], PriceText::withBase(480.0, -20, 600.0, 'BRL'));
        self::assertSame("R$\u{00A0}480", PriceText::fromPercent(-20, 600.0, 'BRL')['text']);
        self::assertSame("R$\u{00A0}400 → R$\u{00A0}380", PriceText::change(400.0, 380.0, 'BRL')['text']);
        self::assertSame("\u{2212}5.0%", PriceText::change(400.0, 380.0, 'BRL')['note']);
        self::assertSame("— → R$\u{00A0}380", PriceText::change(null, 380.0, 'BRL')['text']);
        self::assertSame(['text' => '—', 'note' => '', 'sr' => 'not set'], PriceText::amount(null, 'BRL'));
        self::assertSame('—', PriceText::fromPercent(null, 600.0, 'BRL')['text']);
    }

    public function testANightIsPrintedInUtcWhateverTheSiteTimezone(): void
    {
        $previous = date_default_timezone_get();
        foreach (['America/Sao_Paulo', 'Australia/Sydney'] as $zone) {
            date_default_timezone_set($zone);
            $night = Days::date('2026-11-20');
            self::assertSame('Fri 20 Nov', NightDate::short($night));
            self::assertSame('Friday, 20 November 2026', NightDate::long($night));
            self::assertSame('20–22 Nov', NightDate::range($night, Days::date('2026-11-22')));
            self::assertSame('30 Dec – 2 Jan', NightDate::range(Days::date('2026-12-30'), Days::date('2027-01-02')));
            self::assertSame('20 Nov', NightDate::range($night, $night));
        }
        date_default_timezone_set($previous);
        $instant = new DateTimeImmutable('2026-11-13T10:31:00Z');
        self::assertSame('2026-11-13 07:31', NightDate::instant($instant, 'America/Sao_Paulo'));
        self::assertSame('21:31', NightDate::time($instant, 'Australia/Sydney'));
        self::assertSame('10:31', NightDate::time($instant, 'Not/A_Zone'), 'an unknown zone reads as UTC');
        self::assertSame('12 mins ago', NightDate::relative($instant, $instant->modify('+12 minutes')));
    }

    public function testPagingLabelsAndLimits(): void
    {
        $page = new Paging(1, 50, 230);
        self::assertSame('1–50 of 230', $page->label());
        self::assertFalse($page->hasPrevious());
        self::assertTrue($page->hasNext());
        $last = new Paging(9, 50, 230);
        self::assertSame(5, $last->page, 'a page beyond the last moves back to the last');
        self::assertSame('201–230 of 230', $last->label());
        self::assertSame(200, $last->offset());
        self::assertSame('none', (new Paging(3, 50, 0))->label());
    }

    public function testThePrivacyGuardRefusesGuestDataAndAcceptsInnocentTexts(): void
    {
        foreach (['Call guest@example.com', 'Phone 11 98765-4321', 'Tel +55 (11) 3456-7890', 'Call 11987654321', 'CPF 123.456.789-09', 'CNPJ 12.345.678/0001-90'] as $text) {
            self::assertNotNull(PrivacyGuard::check($text), $text);
        }
        foreach (['Weekend promo 2026', 'Room 12', 'Price 480', 'Booking 75012345', 'Check the heater of room 12 before 20 Nov'] as $text) {
            self::assertNull(PrivacyGuard::check($text), $text);
        }
    }

    public function testTheCsvWriterGuardsFormulasButKeepsItsOwnNumbers(): void
    {
        self::assertSame("'=SUM(A1)", CsvWriter::cell('=SUM(A1)'));
        foreach (['+1', '-2', '@cmd', "\tx", "\rx"] as $text) {
            self::assertSame("'" . $text, CsvWriter::cell($text));
        }
        self::assertSame('-5', CsvWriter::cell(-5));
        self::assertSame('480.5', CsvWriter::cell(480.5));
        self::assertSame('480', CsvWriter::cell(480.0));
        self::assertSame('', CsvWriter::cell(null));
        self::assertSame('ritmo-changes-inn-20261113.csv', CsvWriter::filename('ritmo-changes-inn-20261113.csv'));
        self::assertSame('a-b.csv', CsvWriter::filename('a/b.csv'));

        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        CsvWriter::write($stream, ['night', 'note'], [['2026-11-20', '=1+1'], ['2026-11-21', 'ok, fine']]);
        rewind($stream);
        self::assertSame("night,note\n2026-11-20,'=1+1\n2026-11-21,\"ok, fine\"\n", stream_get_contents($stream));
    }

    public function testRatiosAreTypedAsPercentAndSavedExactly(): void
    {
        self::assertSame(0.7, Ratio::fromPercentText('70'));
        self::assertSame(0.75, Ratio::fromPercentText('75'));
        self::assertSame(0.075, Ratio::fromPercentText('7,5'));
        self::assertSame(1.0, Ratio::fromPercentText('100'));
        self::assertSame('75', Ratio::toPercentText(0.75));
        self::assertSame('7.5', Ratio::toPercentText(0.075));
        $this->expectException(UserFacing::class);
        Ratio::fromPercentText('120');
    }

    public function testATypedConfirmationMustMatchExactly(): void
    {
        ConfirmTyped::check('example-inn', ' example-inn ');
        $this->expectException(UserFacing::class);
        $this->expectExceptionMessage('type example-inn to confirm');
        ConfirmTyped::check('example-inn', 'Example-Inn');
    }

    public function testErrorsOfOthersGetAReferenceAndNeverTheirMessage(): void
    {
        $logged = [];
        $text = ErrorText::from(new RuntimeException('SQLSTATE secret details'), static function (string $reference, array $context) use (&$logged): void {
            $logged = [$reference, $context['class']];
        });
        self::assertMatchesRegularExpression('/^Something went wrong\. Nothing was changed\. Reference E-[0-9a-f]{8}$/', $text);
        self::assertStringNotContainsString('SQLSTATE', $text);
        self::assertSame(RuntimeException::class, $logged[1]);
        self::assertSame('Type a percentage', substr(ErrorText::from(new UserFacing('Type a percentage between 0 and 100, such as 75.')), 0, 17));
    }

    public function testTheMenuHasTwelveEntriesInOrder(): void
    {
        self::assertSame(
            ['ritmo-revenue', 'ritmo-revenue-nights', 'ritmo-revenue-approvals', 'ritmo-revenue-alerts', 'ritmo-revenue-todo', 'ritmo-revenue-changes', 'ritmo-revenue-reports', 'ritmo-revenue-market', 'ritmo-revenue-orders', 'ritmo-revenue-rules', 'ritmo-revenue-operations', 'ritmo-revenue-settings'],
            array_column(Menu::entries(), 'slug')
        );
        self::assertSame(['ritmo-revenue-settings', 'connections'], Menu::REDIRECTS['ritmo-revenue-connections']);
    }
}
