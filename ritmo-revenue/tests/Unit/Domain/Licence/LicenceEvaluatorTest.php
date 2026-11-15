<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Licence;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Licence\GracePolicy;
use Ritmo\Domain\Licence\LicenceEvaluator;
use Ritmo\Domain\Licence\LicenceKey;
use Ritmo\Domain\Licence\LicenceReason;
use Ritmo\Domain\Licence\LicenceRecord;
use Ritmo\Domain\Licence\LicenceStanding;
use Ritmo\Domain\Licence\LicenceVerdict;

final class LicenceEvaluatorTest extends TestCase
{
    private const KEY = 'RR-2026-ABCD-EFGH';

    private static function at(string $instant): DateTimeImmutable
    {
        return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
    }

    private static function keyed(): LicenceRecord
    {
        return LicenceRecord::forKey(LicenceKey::parse(self::KEY));
    }

    private static function evaluate(LicenceRecord $record, string $now, bool $dev = false, int $graceDays = 30): LicenceVerdict
    {
        return (new LicenceEvaluator(new GracePolicy($graceDays)))->evaluate($record, self::at($now), $dev);
    }

    public function testNoKeyIsUnlicensedAndAllowsNothing(): void
    {
        $verdict = self::evaluate(new LicenceRecord(), '2026-11-15T10:00:00Z');

        self::assertSame(LicenceStanding::Unlicensed, $verdict->standing);
        self::assertSame(LicenceReason::NO_KEY, $verdict->reason);
        self::assertFalse($verdict->allowsWriting());
        self::assertFalse($verdict->allowsAi());
        self::assertNull($verdict->graceEndsAt);
    }

    public function testAKeyNeverAnsweredIsNotCheckedYet(): void
    {
        $record = self::keyed()->withAnswer('http', LicenceRecord::UNREACHABLE, null, self::at('2026-11-15T10:00:00Z'));
        $verdict = self::evaluate($record, '2026-11-15T10:00:01Z');

        self::assertSame(LicenceStanding::Unlicensed, $verdict->standing);
        self::assertSame(LicenceReason::NOT_CHECKED, $verdict->reason);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function neverValid(): array
    {
        return [
            'expired from the start' => [LicenceRecord::EXPIRED, LicenceReason::EXPIRED],
            'invalid from the start' => [LicenceRecord::INVALID, LicenceReason::INVALID],
        ];
    }

    #[DataProvider('neverValid')]
    public function testAKeyNeverValidGetsNoGracePeriod(string $status, string $reason): void
    {
        $record = self::keyed()->withAnswer('http', $status, null, self::at('2026-11-15T10:00:00Z'));
        $verdict = self::evaluate($record, '2026-11-15T10:00:01Z');

        self::assertSame(LicenceStanding::Unlicensed, $verdict->standing);
        self::assertSame($reason, $verdict->reason);
        self::assertFalse($verdict->allowsWriting());
    }

    public function testAValidAnswerIsActiveUntilItsEndDate(): void
    {
        $record = self::keyed()->withAnswer('http', LicenceRecord::VALID, self::at('2027-11-15T00:00:00Z'), self::at('2026-11-15T10:00:00Z'));
        $verdict = self::evaluate($record, '2026-11-16T09:00:00Z');

        self::assertSame(LicenceStanding::Active, $verdict->standing);
        self::assertSame(LicenceReason::NONE, $verdict->reason);
        self::assertTrue($verdict->allowsWriting());
        self::assertTrue($verdict->allowsAi());
        self::assertEquals(self::at('2027-11-15T00:00:00Z'), $verdict->expiresAt);
    }

    public function testAServerOutOfReachKeepsTheLastValidAnswerForTheGracePeriod(): void
    {
        $record = self::keyed()
            ->withAnswer('http', LicenceRecord::VALID, null, self::at('2026-11-01T10:00:00Z'))
            ->withAnswer('http', LicenceRecord::UNREACHABLE, null, self::at('2026-11-02T10:00:00Z'));

        $during = self::evaluate($record, '2026-11-30T10:00:00Z');
        self::assertSame(LicenceStanding::Grace, $during->standing);
        self::assertSame(LicenceReason::UNREACHABLE, $during->reason);
        self::assertTrue($during->allowsWriting(), 'the grace period keeps everything working');
        self::assertEquals(self::at('2026-12-01T10:00:00Z'), $during->graceEndsAt, '30 days after the last valid answer');
        self::assertSame(1, $during->graceDaysLeft(self::at('2026-11-30T10:00:00Z')));

        $after = self::evaluate($record, '2026-12-01T10:00:00Z');
        self::assertSame(LicenceStanding::Lapsed, $after->standing);
        self::assertFalse($after->allowsWriting());
        self::assertFalse($after->allowsAi());
        self::assertSame(0, $after->graceDaysLeft(self::at('2026-12-01T10:00:00Z')));
    }

    public function testTheGracePeriodIsConfigurable(): void
    {
        $record = self::keyed()
            ->withAnswer('http', LicenceRecord::VALID, null, self::at('2026-11-01T10:00:00Z'))
            ->withAnswer('http', LicenceRecord::UNREACHABLE, null, self::at('2026-11-02T10:00:00Z'));

        self::assertSame(LicenceStanding::Lapsed, self::evaluate($record, '2026-11-08T10:00:00Z', false, 7)->standing);
        self::assertSame(LicenceStanding::Grace, self::evaluate($record, '2026-11-08T09:59:59Z', false, 7)->standing);
    }

    public function testNoSuccessfulCheckForTheWholeGracePeriodLapsesEvenWithoutAFailedAttempt(): void
    {
        $record = self::keyed()->withAnswer('http', LicenceRecord::VALID, null, self::at('2026-10-01T10:00:00Z'));

        self::assertSame(LicenceStanding::Active, self::evaluate($record, '2026-10-31T09:59:59Z')->standing);
        $stale = self::evaluate($record, '2026-10-31T10:00:00Z');
        self::assertSame(LicenceStanding::Lapsed, $stale->standing);
        self::assertSame(LicenceReason::UNREACHABLE, $stale->reason);
    }

    public function testAnAnswerAfterTheOutageEndsTheGracePeriod(): void
    {
        $record = self::keyed()
            ->withAnswer('http', LicenceRecord::VALID, null, self::at('2026-11-01T10:00:00Z'))
            ->withAnswer('http', LicenceRecord::UNREACHABLE, null, self::at('2026-11-20T10:00:00Z'))
            ->withAnswer('http', LicenceRecord::VALID, null, self::at('2026-11-25T10:00:00Z'));

        self::assertSame(LicenceStanding::Active, self::evaluate($record, '2026-12-10T10:00:00Z')->standing);
    }

    public function testAnEndDatePassingSinceTheLastValidAnswerStartsTheGracePeriod(): void
    {
        $record = self::keyed()->withAnswer('http', LicenceRecord::VALID, self::at('2026-11-20T00:00:00Z'), self::at('2026-11-15T10:00:00Z'));

        self::assertSame(LicenceStanding::Active, self::evaluate($record, '2026-11-19T23:59:59Z')->standing);
        $grace = self::evaluate($record, '2026-11-20T00:00:00Z');
        self::assertSame(LicenceStanding::Grace, $grace->standing);
        self::assertSame(LicenceReason::EXPIRED, $grace->reason);
        self::assertEquals(self::at('2026-12-20T00:00:00Z'), $grace->graceEndsAt);
        self::assertSame(LicenceStanding::Lapsed, self::evaluate($record, '2026-12-20T00:00:00Z')->standing);
    }

    public function testAnEndDateAlreadyPastWhenTheServerSaidValidDoesNotCount(): void
    {
        $record = self::keyed()->withAnswer('http', LicenceRecord::VALID, self::at('2026-11-01T00:00:00Z'), self::at('2026-11-15T10:00:00Z'));

        self::assertSame(LicenceStanding::Active, self::evaluate($record, '2026-11-16T10:00:00Z')->standing);
    }

    public function testAnExpiredAnswerAfterAValidOneGivesGraceFromTheEndDate(): void
    {
        $record = self::keyed()
            ->withAnswer('http', LicenceRecord::VALID, self::at('2026-11-20T00:00:00Z'), self::at('2026-11-15T10:00:00Z'))
            ->withAnswer('http', LicenceRecord::EXPIRED, self::at('2026-11-20T00:00:00Z'), self::at('2026-11-21T10:00:00Z'));

        $verdict = self::evaluate($record, '2026-11-25T10:00:00Z');
        self::assertSame(LicenceStanding::Grace, $verdict->standing);
        self::assertSame(LicenceReason::EXPIRED, $verdict->reason);
        self::assertEquals(self::at('2026-12-20T00:00:00Z'), $verdict->graceEndsAt);
        self::assertSame(LicenceStanding::Lapsed, self::evaluate($record, '2026-12-20T00:00:01Z')->standing);
    }

    public function testAnOldEndDateNeverShortensTheGraceBeforeTheLastValidAnswer(): void
    {
        // The server said valid yesterday, today it says the licence ended a year ago.
        $record = self::keyed()
            ->withAnswer('http', LicenceRecord::VALID, null, self::at('2026-11-14T10:00:00Z'))
            ->withAnswer('http', LicenceRecord::EXPIRED, self::at('2025-11-01T00:00:00Z'), self::at('2026-11-15T10:00:00Z'));

        $verdict = self::evaluate($record, '2026-11-15T10:00:01Z');
        self::assertSame(LicenceStanding::Grace, $verdict->standing);
        self::assertEquals(self::at('2026-12-14T10:00:00Z'), $verdict->graceEndsAt);
    }

    public function testARevokedKeyGetsGraceFromTheFirstInvalidAnswer(): void
    {
        $record = self::keyed()
            ->withAnswer('http', LicenceRecord::VALID, null, self::at('2026-11-10T10:00:00Z'))
            ->withAnswer('http', LicenceRecord::INVALID, null, self::at('2026-11-11T10:00:00Z'))
            ->withAnswer('http', LicenceRecord::INVALID, null, self::at('2026-11-12T10:00:00Z'));

        $verdict = self::evaluate($record, '2026-11-20T10:00:00Z');
        self::assertSame(LicenceStanding::Grace, $verdict->standing);
        self::assertSame(LicenceReason::INVALID, $verdict->reason);
        self::assertEquals(self::at('2026-12-11T10:00:00Z'), $verdict->graceEndsAt, 'from the first invalid answer, not the latest');
        self::assertSame(LicenceStanding::Lapsed, self::evaluate($record, '2026-12-11T10:00:00Z')->standing);
    }

    public function testATestKeyCountsOnlyInDevelopmentMode(): void
    {
        $record = LicenceRecord::forKey(LicenceKey::parse('TEST-VALID-0001'))->withAnswer(LicenceRecord::TEST_PROVIDER, LicenceRecord::VALID, self::at('2027-11-15T00:00:00Z'), self::at('2026-11-15T10:00:00Z'));

        self::assertSame(LicenceStanding::Active, self::evaluate($record, '2026-11-15T11:00:00Z', true)->standing);
        $production = self::evaluate($record, '2026-11-15T11:00:00Z', false);
        self::assertSame(LicenceStanding::Unlicensed, $production->standing);
        self::assertSame(LicenceReason::TEST_PROVIDER, $production->reason);
        self::assertFalse($production->allowsWriting());
    }
}
