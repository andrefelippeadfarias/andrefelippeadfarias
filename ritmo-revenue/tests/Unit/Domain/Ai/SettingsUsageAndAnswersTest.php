<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Ai;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Ai\AiEffort;
use Ritmo\Domain\Ai\AiModel;
use Ritmo\Domain\Ai\AiPurpose;
use Ritmo\Domain\Ai\AiReply;
use Ritmo\Domain\Ai\AiSettings;
use Ritmo\Domain\Ai\AiStopped;
use Ritmo\Domain\Ai\AnswerReader;
use Ritmo\Domain\Ai\MonthlyUsage;
use Ritmo\Domain\Ai\PriceTable;
use Ritmo\Domain\Ai\PromptBuilder;
use Ritmo\Domain\Ai\TokenUsage;
use Ritmo\Domain\Ai\WeeklyFacts;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Report\Booking\BookingTotals;
use Ritmo\Domain\Report\Month\MonthStays;
use Ritmo\Domain\Report\Weekly\MadeWeek;
use Ritmo\Domain\Report\Weekly\Moment;
use Ritmo\Domain\Report\Weekly\ShareRow;
use Ritmo\Domain\Report\Weekly\StayWeek;
use Ritmo\Domain\Report\Weekly\WeeklyReport;
use Ritmo\Domain\Support\Days;

/**
 * The settings (off by default, Opus 5.5, medium effort, fallback on, a monthly cap), the cost of
 * a call at the price table, the month's usage without content, the reading of an answer (stop
 * reason first) and the weekly facts.
 */
final class SettingsUsageAndAnswersTest extends TestCase
{
    public function testTheAssistantIsOffByDefaultWithTheDocumentedDefaults(): void
    {
        $settings = AiSettings::defaults();

        self::assertSame([false, 'claude-opus-5-5', AiEffort::Medium, true, 20.0, 120], [$settings->enabled, $settings->model, $settings->effort, $settings->fallback, $settings->monthlyCapUsd, $settings->timeoutSeconds]);
        self::assertSame(['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'], AiModel::CHOICES);
        self::assertSame($settings->toArray(), AiSettings::fromArray($settings->toArray())->toArray());
    }

    public function testSettingsThatDoNotReadAreRefusedFieldByField(): void
    {
        try {
            AiSettings::fromArray(['model' => 'claude-opus-5-5-20260101', 'effort' => 'max', 'monthly_cap_usd' => 0, 'timeout_seconds' => 900, 'prices' => ['gpt' => []]]);
            self::fail('refused');
        } catch (InvalidConfig $e) {
            self::assertSame(['effort', 'prices', 'model', 'monthly_cap_usd', 'timeout_seconds'], array_map(static fn ($error): string => $error->path, $e->errors()));
        }
    }

    public function testAModelTheKeyCannotUseIsFlagged(): void
    {
        $settings = AiSettings::defaults()->with(['available_models' => ['claude-haiku-5-5']]);
        self::assertFalse($settings->modelAvailable());
        self::assertTrue($settings->with(['model' => 'claude-haiku-5-5'])->modelAvailable());
        self::assertTrue(AiSettings::defaults()->modelAvailable(), 'unknown until the connection is tested');
    }

    public function testTheCostFollowsThePriceTable(): void
    {
        $usage = new TokenUsage(1000000, 100000, 200000, 500000);

        self::assertEqualsWithDelta(4.0 + 2.0 + 1.0 + 0.1, $usage->costUsd(PriceTable::defaults()->forModel(AiModel::OPUS)), 1e-9);
        self::assertEqualsWithDelta(0.1 + 0.05 + 0.025 + 0.005, $usage->costUsd(PriceTable::defaults()->forModel(AiModel::HAIKU)), 1e-9);
        self::assertEqualsWithDelta(5.0 + 2.5 + 1.25 + 0.25, $usage->costUsd(PriceTable::defaults()->forModel('claude-opus-4-8')), 1e-9, 'another model, such as a fallback, is priced high');
        $custom = PriceTable::fromArray([AiModel::OPUS => ['input' => 5, 'output' => 25, 'cache_read' => 0.5, 'cache_write' => 6.25]]);
        self::assertSame(5.0, $custom->forModel(AiModel::OPUS)->input);
        self::assertSame(2.0, $custom->forModel(AiModel::SONNET)->input, 'a row left out keeps its default');
    }

    public function testTheMonthKeepsTotalsAndCallsWithoutContent(): void
    {
        $month = new MonthlyUsage('2026-11');
        $reply = new AiReply('secret text of the answer', 'end_turn', new TokenUsage(1000, 200, 0, 3000), 'claude-opus-5-5');
        for ($i = 0; $i < MonthlyUsage::KEEP_CALLS + 5; ++$i) {
            $month = $month->with('2026-11-15T10:00:00Z', AiPurpose::WeeklyReport, $reply, PriceTable::defaults());
        }
        $month = $month->with('2026-11-15T11:00:00Z', AiPurpose::RequestDraft, new AiReply('', 'refusal', new TokenUsage(500, 0), 'claude-opus-4-8', true), PriceTable::defaults());

        self::assertSame(MonthlyUsage::KEEP_CALLS + 6, $month->totals['calls']);
        self::assertCount(MonthlyUsage::KEEP_CALLS, $month->calls);
        self::assertSame([1, 1], [$month->totals['fallbacks'], $month->totals['refusals']]);
        self::assertStringNotContainsString('secret text', (string) json_encode($month->toArray()));
        self::assertSame(['purpose' => 'request_draft', 'model' => 'claude-opus-4-8', 'fallback' => true, 'stop' => 'refusal'], array_intersect_key($month->calls[MonthlyUsage::KEEP_CALLS - 1], ['purpose' => 0, 'model' => 0, 'fallback' => 0, 'stop' => 0]));
        self::assertTrue($month->capReached(1.0));
        self::assertFalse($month->capReached(100.0));
        self::assertEquals($month, MonthlyUsage::fromArray('2026-11', $month->toArray()));
        self::assertSame(0, MonthlyUsage::fromArray('2026-12', $month->toArray())->totals['calls'], 'a new month starts at zero');
    }

    public function testTheStopReasonIsReadBeforeTheContent(): void
    {
        $request = (new PromptBuilder())->report(AiPurpose::WeeklyReport, [], [['label' => 'Bookings', 'value' => '4']], 'en');
        $cases = [
            [new AiReply('', 'refusal', new TokenUsage(), 'claude-opus-5-5', false, 'cyber'), AiStopped::REFUSAL],
            [new AiReply('Four bookings were', 'max_tokens', new TokenUsage(), 'claude-opus-5-5'), AiStopped::MAX_TOKENS],
            [new AiReply('', 'end_turn', new TokenUsage(), 'claude-opus-5-5'), AiStopped::INVALID_OUTPUT],
            [new AiReply('There were 5 bookings.', 'end_turn', new TokenUsage(), 'claude-opus-5-5'), AiStopped::UNKNOWN_NUMBER],
            [new AiReply('Write to owner@example.com: 4 bookings.', 'end_turn', new TokenUsage(), 'claude-opus-5-5'), AiStopped::PRIVACY],
        ];
        foreach ($cases as [$reply, $reason]) {
            try {
                AnswerReader::text($reply, $request);
                self::fail($reason);
            } catch (AiStopped $e) {
                self::assertSame($reason, $e->reason);
            }
        }
        self::assertSame('There were 4 bookings.', AnswerReader::text(new AiReply(' There were 4 bookings. ', 'end_turn', new TokenUsage(), 'claude-opus-5-5'), $request));
    }

    public function testAnAlertAnswerIsCheckedLikeAText(): void
    {
        $request = (new PromptBuilder())->alert(['code' => 'x', 'summary' => '2 nights closed.'], 'en');

        self::assertSame('2 nights are closed.', AnswerReader::alert(new AiReply('{"explanation":"2 nights are closed.","action":"Open the Nights screen."}', 'end_turn', new TokenUsage(), 'm'), $request)['explanation']);
        $this->expectException(AiStopped::class);
        AnswerReader::alert(new AiReply('{"explanation":"3 nights are closed.","action":""}', 'end_turn', new TokenUsage(), 'm'), $request);
    }

    public function testTheWeeklyFactsComeFromTheReportOfOneProperty(): void
    {
        $totals = new BookingTotals(5, 1, 4, 9, 4500.0, 500.0, 12.0, 2.25, 300.0, 0);
        $report = new WeeklyReport(
            Days::date('2026-11-15'),
            Days::date('2026-09-28'),
            [new MadeWeek(Days::date('2026-11-02'), 'inn-1', true, $totals, 1, 300.0), new MadeWeek(Days::date('2026-11-02'), 'inn-2', true, $totals, 0, 0.0)],
            [new StayWeek(Days::date('2026-11-09'), 'inn-1', Moment::Current, 20, 28, 0.7143, 9000.0, 450.0, 321.43), new StayWeek(Days::date('2026-11-02'), 'inn-1', Moment::Past, 1, 28, 0.04, 100.0, 100.0, 3.57)],
            ['2026-11' => ['inn-1' => new MonthStays('inn-1', 120, 14, 50, 25000.0, 0.4167, 500.0, 208.33, 16, 30, 15000.0, 0.3125, 80, 40000.0, 0.3333, 500.0, 50000.0, 10000.0, 40, 250.0)]],
            [new ShareRow('inn-1', 'booking', $totals, 0.75)],
            [],
        );

        $facts = WeeklyFacts::of($report, 'inn-1');
        $labels = array_column($facts, 'label');

        self::assertSame(['label' => 'Today', 'value' => '2026-11-15', 'kind' => 'date'], $facts[0]);
        self::assertContains('Last complete week (from 2026-11-02): bookings made', $labels);
        self::assertContains('Stays this week: occupancy', $labels);
        self::assertContains('2026-11 gap to the minimum', $labels);
        self::assertContains('Channel booking share of revenue, last 28 days', $labels);
        self::assertNotContains('Stays of the week from 2026-11-02: occupancy', $labels, 'past stay weeks are left out');
        $formatted = WeeklyFacts::formatted($facts, static fn (int|float|string $v, string $kind): string => $kind === WeeklyFacts::SHARE ? ((float) $v * 100) . '%' : (string) $v);
        self::assertContains(['label' => 'Stays this week: occupancy', 'value' => '71.43%'], $formatted);
    }
}
