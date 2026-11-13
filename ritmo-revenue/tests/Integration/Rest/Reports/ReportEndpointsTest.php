<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Rest\Reports;

require_once __DIR__ . '/stubs.php';

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Model\Property;
use Ritmo\Rest\Reports\ReportEndpoints;
use Ritmo\Tests\Unit\Application\Report\ReportWorld;

/**
 * The answers of the reports REST routes over a real database (SQLite): summary sentences whose
 * numbers are those of the tables' totals, rows as a person reads them (money with the currency,
 * shares in percent), the targets saved and refused in words, and a run's report lines with
 * their text; no guest field, no JSON of internal names.
 */
final class ReportEndpointsTest extends TestCase
{
    private ReportWorld $world;

    protected function setUp(): void
    {
        $this->world = new ReportWorld();
        $this->world->book('B1', 'STD', '2026-11-14', 2, 900.0, '2026-11-02T15:00:00Z');
        $this->world->book('B2', 'GDN', '2026-11-20', 1, 700.0, '2026-11-10T12:00:00Z', BookingStatus::NewBooking, 'airbnb');
    }

    public function testTheChannelsSummaryEqualsTheTotalsRow(): void
    {
        [$status, $body] = $this->endpoints()->report('channels', 'inn');
        self::assertSame(200, $status);
        self::assertSame("Last 28 days: 2 bookings made for R$\u{00A0}1,600.", $body['summary']);
        $table = $body['tables'][0];
        self::assertSame('weekly-channels', $table['id']);
        self::assertSame(['Airbnb', 'Booking.com'], array_column($table['rows'], 0));
        self::assertSame(['Total', '2', '0', '2', '3', "R$\u{00A0}1,600"], array_slice($table['totals'], 0, 6));
        self::assertSame('44%', $table['rows'][0][9], 'a share in percent, never a fraction');
    }

    public function testEveryReportAnswersWithCaptionsAndColumns(): void
    {
        foreach (['weekly', 'month', 'rooms', 'review', 'targets', 'occupancy'] as $tab) {
            [$status, $body] = $this->endpoints()->report($tab, 'inn', '2026-11');
            self::assertSame(200, $status, $tab);
            self::assertNotSame('', $body['summary'], $tab);
            foreach ($body['tables'] as $table) {
                self::assertNotSame('', $table['caption'], $tab);
                self::assertNotSame([], $table['columns'], $tab);
                self::assertStringNotContainsString('_', implode(' ', $table['columns']), "$tab: labels, not internal names");
            }
        }
    }

    public function testTargetsAreSavedOrRefusedInWords(): void
    {
        [$status, $body] = $this->endpoints()->saveTargets('inn', '2026-12', ['minimum' => '12000', 'market_sold_percent' => '20'], 3);
        self::assertSame([200, '2026-12'], [$status, $body['month']]);
        self::assertSame(12000.0, $this->world->targets->forProperty('inn', ['2026-12'])['2026-12']->value('minimum'));
        [$status, $body] = $this->endpoints()->saveTargets('inn', '2026-12', ['market_sold_percent' => '120'], 3);
        self::assertSame(400, $status);
        self::assertStringContainsString('percentages from 0 to 100', $body['text']);
        self::assertSame(400, $this->endpoints()->saveTargets('inn', '2026-13', [], 3)[0]);
        self::assertSame(400, $this->endpoints()->saveTargets('inn', '2026-12', ['minimum' => 'abc'], 3)[0]);
    }

    public function testASnapshotIsSavedAndAnUnknownPropertyIsNotFound(): void
    {
        [$status, $body] = $this->endpoints()->snapshot('inn', 3);
        self::assertSame([200, '2026-11-13'], [$status, $body['saved']]);
        self::assertSame([404, ['text' => 'That property does not exist.']], $this->endpoints()->report('weekly', 'nope'));
    }

    public function testRunLinesComeWithTheirTextAndOldRunsAsRecorded(): void
    {
        $runs = [
            1 => ['summary' => ['report' => ['items' => [['code' => 'shadow_would_write', 'params' => ['items' => 3, 'pms' => 1]], ['code' => 'bogus', 'params' => ['email' => 'x']]]]]],
            2 => ['summary' => ['report' => ['lines' => ['Shadow: 2 item(s) would be written; writes sent: 0.']]]],
        ];
        $endpoints = $this->endpoints($runs);
        [$status, $body] = $endpoints->runReport(1);
        self::assertSame(200, $status);
        self::assertSame('Shadow mode: 3 change would be written to the pricing system and 1 to the PMS; nothing was sent.', $body['lines'][0]['text']);
        self::assertSame(['items' => 3, 'pms' => 1], $body['lines'][0]['params']);
        self::assertSame(['code' => null, 'params' => [], 'text' => 'Unknown report line'], $body['lines'][1], 'never the raw parameters');
        self::assertSame([true, 'Shadow: 2 item(s) would be written; writes sent: 0.'], [$endpoints->runReport(2)[1]['recorded'], $endpoints->runReport(2)[1]['lines'][0]['text']]);
        self::assertSame(404, $endpoints->runReport(9)[0]);
    }

    /**
     * @param array<int, array<string, mixed>> $runs
     */
    private function endpoints(array $runs = []): ReportEndpoints
    {
        $w = $this->world;

        return new ReportEndpoints(
            $w->service,
            $w->targets,
            static fn (string $id): ?Property => $id === 'inn' ? $w->scope->property : null,
            static fn (Property $p) => $w->scope,
            static fn (int $id): ?array => $runs[$id] ?? null,
            $w->now(),
        );
    }
}
