<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Write;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Write\Quota\AttemptLog;
use Ritmo\Application\Write\Quota\AttemptOutcome;
use Ritmo\Application\Write\Quota\AttemptRefused;
use Ritmo\Domain\Write\QuotaConfig;
use Ritmo\Application\Write\Quota\QuotaVerdict;
use Ritmo\Application\Write\Quota\RecalcAttempt;
use Ritmo\Application\Write\Quota\RecalcQuota;
use Ritmo\Domain\Write\WriteConfig;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Tests\Support\Fixtures;

/**
 * What the plugin adds to the reference's quota: the attempt recorded before the call (a pending
 * attempt older than the call timeout is a failure, so a crash never under-counts), the run start
 * of a run started by hand, instants with a timezone only, and the two settings sections.
 */
final class QuotaRulesTest extends TestCase
{
    private static function at(string $text): DateTimeImmutable
    {
        return AttemptLog::instant($text);
    }

    public function testAPendingAttemptCountsAndBecomesAFailureAfterTheTimeout(): void
    {
        $quota = new RecalcQuota(new QuotaConfig(futureSlackSeconds: 0), 120);
        $pending = [new RecalcAttempt('L1', self::at('2027-03-03T12:00:00Z'), AttemptOutcome::Pending)];
        $runStart = self::at('2027-03-03T11:00:00Z');

        $soon = $quota->verdict($pending, 'L1', self::at('2027-03-03T12:01:00Z'), $runStart);
        self::assertTrue($soon->allowed);
        self::assertSame(1, $soon->used);

        $late = $quota->verdict($pending, 'L1', self::at('2027-03-03T12:03:00Z'), $runStart);
        self::assertSame([QuotaVerdict::ROOM_FAILURE_THIS_RUN], $late->blockedBy, 'a crash mid-call is a failure of this run');
    }

    public function testARunStartedByHandWaitsTheCooldownAfterAFailure(): void
    {
        $quota = new RecalcQuota(new QuotaConfig(failureCooldownMinutes: 60));
        $now = self::at('2027-03-03T12:30:00Z');
        $slot = self::at('2027-03-03T12:20:00Z');

        self::assertEquals($slot, $quota->runStartFor($slot, $now, true));
        self::assertEquals(self::at('2027-03-03T11:30:00Z'), $quota->runStartFor($slot, $now, false));
        $failed = [new RecalcAttempt('L1', self::at('2027-03-03T12:00:00Z'), AttemptOutcome::Failure, 'timeout')];
        self::assertTrue($quota->verdict($failed, 'L1', $now, $quota->runStartFor($slot, $now, true))->allowed);
        self::assertFalse($quota->verdict($failed, 'L1', $now, $quota->runStartFor($slot, $now, false))->allowed);
    }

    public function testAnInstantWithoutATimezoneIsRefused(): void
    {
        self::assertSame('2027-03-03T10:00:00+00:00', self::at('2027-03-03T07:00:00-03:00')->format(DATE_ATOM));
        $this->expectException(AttemptRefused::class);
        self::at('2027-03-03T07:00:00');
    }

    public function testTheErrorOfAnAttemptIsOneShortLine(): void
    {
        $attempt = new RecalcAttempt('L1', self::at('2027-03-03T12:00:00.900Z'), AttemptOutcome::Failure, "line one\n  line two " . str_repeat('x', 400));

        self::assertSame('2027-03-03T12:00:00+00:00', $attempt->at->format(DATE_ATOM), 'whole seconds');
        self::assertSame(300, mb_strlen((string) $attempt->error));
        self::assertStringStartsWith('line one line two x', (string) $attempt->error);
    }

    public function testTheSettingsHaveNeutralDefaultsAndRefuseValuesOutOfRange(): void
    {
        self::assertEquals(new WriteConfig(), WriteConfig::fromArray([]));
        self::assertEquals(new QuotaConfig(), QuotaConfig::fromArray([]));
        self::assertFalse((new WriteConfig())->listingChangesEnabled, 'listing changes stay off unless turned on');
        $parity = Fixtures::json('presets/parity-write.json');
        self::assertTrue(WriteConfig::fromArray($parity['write'])->listingChangesEnabled);
        self::assertSame(0, QuotaConfig::fromArray($parity['quota'])->failureCooldownMinutes);

        $this->expectException(InvalidConfig::class);
        WriteConfig::fromArray(['token_ttl_minutes' => 0, 'pms_max_ranges_per_post' => 101]);
    }

    /**
     * The quota is the provider's limit counted in 24 rolling hours (task rule 9(f)): the settings
     * can make it stricter, never looser. Review finding of the M3 correction phase: per_listing 10
     * with a 1 h window allowed 240 recalculations a day.
     */
    public function testTheQuotaCanOnlyBeMadeStricterThanTheProviderLimits(): void
    {
        foreach ([['per_listing' => 10], ['per_listing' => 4], ['window_hours' => 1], ['window_hours' => 23], ['per_account_per_minute' => 11], ['account_wait_after_429_seconds' => 0]] as $loose) {
            try {
                QuotaConfig::fromArray($loose);
                self::fail('accepted ' . json_encode($loose));
            } catch (InvalidConfig) {
                self::addToAssertionCount(1);
            }
        }
        $strict = QuotaConfig::fromArray(['per_listing' => 2, 'window_hours' => 48, 'per_account_per_minute' => 5, 'account_wait_after_429_seconds' => 120]);
        self::assertSame([2, 48, 5, 120], [$strict->perListing, $strict->windowHours, $strict->perAccountPerMinute, $strict->accountWaitAfter429Seconds]);

        $this->expectException(\InvalidArgumentException::class);
        new QuotaConfig(perListing: 10, windowHours: 1);
    }
}
