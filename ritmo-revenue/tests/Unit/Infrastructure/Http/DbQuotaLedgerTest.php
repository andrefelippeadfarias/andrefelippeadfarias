<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Http;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Contracts\QuotaLedger;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\QuotaLogRepository;
use Ritmo\Infrastructure\Http\DbQuotaLedger;
use Ritmo\Tests\Unit\Infrastructure\Support\MigratedDatabase;

/**
 * The scenarios follow the reference quota of the pricing system: 3 recalculations per
 * listing in any 24 hours and 10 per account per minute, failures included.
 */
final class DbQuotaLedgerTest extends TestCase
{
    private const DAY = 86400;

    private PdoDatabase $db;

    private DbQuotaLedger $ledger;

    protected function setUp(): void
    {
        $this->db = MigratedDatabase::create();
        $this->ledger = new DbQuotaLedger(new QuotaLogRepository($this->db));
    }

    public function testItIsTheQuotaLedgerOfTheContracts(): void
    {
        self::assertInstanceOf(QuotaLedger::class, $this->ledger);
    }

    public function testAFailedCallSpendsQuotaToo(): void
    {
        $this->ledger->record('pricing', 'recalculation', 'L-1', true, $this->at('2026-11-06 08:00:00'));
        $this->ledger->record('pricing', 'recalculation', 'L-1', false, $this->at('2026-11-06 09:00:00'));
        $this->ledger->record('pricing', 'recalculation', 'L-1', false, $this->at('2026-11-06 10:00:00'));

        self::assertSame(3, $this->ledger->countSince('pricing', 'recalculation', 'L-1', $this->at('2026-11-06 00:00:00')));
        self::assertSame(3, $this->ledger->countInWindow('pricing', 'recalculation', 'L-1', self::DAY, $this->at('2026-11-06 11:00:00')));
        self::assertSame(
            '2026-11-07 08:00:00',
            $this->ledger->opensAt('pricing', 'recalculation', 'L-1', 3, self::DAY, $this->at('2026-11-06 11:00:00'))?->format('Y-m-d H:i:s'),
            'the fourth call fits 24 hours after the oldest of the three'
        );
    }

    public function testTheWindowRollsItIsNotTheCalendarDay(): void
    {
        $this->ledger->record('pricing', 'recalculation', 'L-1', true, $this->at('2026-11-06 20:00:00'));
        $this->ledger->record('pricing', 'recalculation', 'L-1', true, $this->at('2026-11-06 22:00:00'));
        $this->ledger->record('pricing', 'recalculation', 'L-1', true, $this->at('2026-11-07 07:53:00'));

        $morning = $this->at('2026-11-07 10:53:00');
        self::assertSame(3, $this->ledger->countInWindow('pricing', 'recalculation', 'L-1', self::DAY, $morning), 'yesterday evening still counts this morning');
        self::assertNotNull($this->ledger->opensAt('pricing', 'recalculation', 'L-1', 3, self::DAY, $morning));

        $evening = $this->at('2026-11-07 20:00:00');
        self::assertSame(2, $this->ledger->countInWindow('pricing', 'recalculation', 'L-1', self::DAY, $evening), 'a call exactly 24 hours old has left the window');
        self::assertNull($this->ledger->opensAt('pricing', 'recalculation', 'L-1', 3, self::DAY, $evening));

        self::assertSame(3, $this->ledger->countInWindow('pricing', 'recalculation', 'L-1', self::DAY, $this->at('2026-11-07 19:59:59')));
    }

    public function testCountSinceIncludesTheInstantItself(): void
    {
        $this->ledger->record('pricing', 'recalculation', 'L-1', true, $this->at('2026-11-06 20:00:00'));

        self::assertSame(1, $this->ledger->countSince('pricing', 'recalculation', 'L-1', $this->at('2026-11-06 20:00:00')));
        self::assertSame(0, $this->ledger->countSince('pricing', 'recalculation', 'L-1', $this->at('2026-11-06 20:00:01')));
    }

    public function testEachScopeHasItsOwnCountAndTheEmptyScopeIsTheAccount(): void
    {
        $minute = $this->at('2026-11-06 08:00:00');
        foreach (['L-1', 'L-1', 'L-2', 'L-3'] as $index => $listing) {
            $this->ledger->record('pricing', 'recalculation', $listing, $index !== 1, $minute->modify("+$index seconds"));
        }
        $this->ledger->record('pricing', 'listing_update', 'L-1', true, $minute);
        $this->ledger->record('other', 'recalculation', 'L-1', true, $minute);
        $now = $minute->modify('+30 seconds');

        self::assertSame(2, $this->ledger->countInWindow('pricing', 'recalculation', 'L-1', self::DAY, $now));
        self::assertSame(1, $this->ledger->countInWindow('pricing', 'recalculation', 'L-2', self::DAY, $now));
        self::assertSame(0, $this->ledger->countInWindow('pricing', 'recalculation', 'L-9', self::DAY, $now));
        self::assertSame(4, $this->ledger->countInWindow('pricing', 'recalculation', '', 60, $now), 'the account counts the calls of every listing');
        self::assertSame(4, $this->ledger->countSince('pricing', 'recalculation', '', $minute));
        self::assertSame(1, $this->ledger->countSince('pricing', 'listing_update', '', $minute));
    }

    public function testTheAccountLimitPerMinuteOpensWhenTheOldestCallLeaves(): void
    {
        $start = $this->at('2026-11-06 08:00:00');
        for ($call = 0; $call < 10; ++$call) {
            $this->ledger->record('pricing', 'recalculation', 'L-' . $call, true, $start->modify("+$call seconds"));
        }

        $now = $start->modify('+15 seconds');
        self::assertSame(10, $this->ledger->countInWindow('pricing', 'recalculation', '', 60, $now));
        self::assertSame('08:01:00', $this->ledger->opensAt('pricing', 'recalculation', '', 10, 60, $now)?->format('H:i:s'));
        self::assertNull($this->ledger->opensAt('pricing', 'recalculation', '', 11, 60, $now));
        self::assertNull($this->ledger->opensAt('pricing', 'recalculation', '', 10, 60, $start->modify('+60 seconds')));
    }

    public function testACallCloseToTheEdgeOfTheWindowIsNeverForgottenEarly(): void
    {
        // Recorded with a fraction of a second: stored rounded up.
        $this->ledger->record('pricing', 'recalculation', 'L-1', true, new DateTimeImmutable('2026-11-06T08:00:00.900000Z'));

        self::assertSame(1, $this->ledger->countInWindow('pricing', 'recalculation', 'L-1', 60, new DateTimeImmutable('2026-11-06T08:01:00.500000Z')), 'really 59.6 s old');
        self::assertSame(0, $this->ledger->countInWindow('pricing', 'recalculation', 'L-1', 60, new DateTimeImmutable('2026-11-06T08:01:01.000000Z')));
    }

    public function testTheQuotaSurvivesAcrossLedgerObjects(): void
    {
        $this->ledger->record('pricing', 'recalculation', 'L-1', true, $this->at('2026-11-06 08:00:00'));

        $later = new DbQuotaLedger(new QuotaLogRepository($this->db));
        self::assertSame(1, $later->countInWindow('pricing', 'recalculation', 'L-1', self::DAY, $this->at('2026-11-06 12:00:00')));
    }

    public function testLimitsAndWindowsMustBePositive(): void
    {
        $now = $this->at('2026-11-06 08:00:00');
        foreach ([
            fn () => $this->ledger->opensAt('pricing', 'recalculation', 'L-1', 0, 60, $now),
            fn () => $this->ledger->opensAt('pricing', 'recalculation', 'L-1', 3, 0, $now),
            fn () => $this->ledger->countInWindow('pricing', 'recalculation', 'L-1', 0, $now),
        ] as $call) {
            try {
                $call();
                self::fail('The call must be refused.');
            } catch (InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(3);
    }

    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc . ' UTC');
    }
}
