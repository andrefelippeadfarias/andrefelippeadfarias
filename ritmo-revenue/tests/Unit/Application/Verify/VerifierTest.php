<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Verify;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Verify\ConfirmationQuota;
use Ritmo\Application\Verify\NightConfirmation;
use Ritmo\Application\Verify\StoredStatus;
use Ritmo\Application\Verify\Verifier;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\ListingInfo;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Model\SyncStatus;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Confirm\LiveRoute;
use Ritmo\Domain\Write\Confirm\LiveStatus;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\State\WriteKind;
use Ritmo\Domain\Write\State\WrittenNight;
use Ritmo\Tests\Fakes\InMemoryPmsConnector;
use Ritmo\Tests\Fakes\InMemoryPricingConnector;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * The Verifier reads back what was written (stored) and, when the calendar allows, checks it in
 * force (live); without a recalculation slot it says "not confirmed" instead of waiting.
 */
final class VerifierTest extends TestCase
{
    private const WRITTEN_AT = '2027-03-03T12:00:00Z';

    public function testWhatWasWrittenIsReadBackPerNight(): void
    {
        $verifier = new Verifier(self::pricing(), self::pms());
        $confirmations = $verifier->stored([
            self::rule('2027-03-10', [ChangeField::FLOOR_PCT => -20]),
            self::rule('2027-03-11', [ChangeField::FLOOR_PCT => -20]),
            new WrittenNight(Provider::Pms, 'Deluxe', Days::date('2027-03-10'), WriteKind::Write, new NightFields([ChangeField::PMS_MIN_STAY => 2]), NightFields::none(), self::at()),
            self::rule('2027-03-10', [ChangeField::FLOOR_PCT => -10], 'Standard'),
        ], self::rooms());

        self::assertSame([StoredStatus::Stored, StoredStatus::Mismatch, StoredStatus::Stored, StoredStatus::Unread], array_map(static fn (NightConfirmation $c): StoredStatus => $c->stored, $confirmations));
        self::assertSame([ChangeField::FLOOR_PCT], $confirmations[1]->differences);
    }

    public function testACalendarRecalculatedAfterTheWriteIsComparedWithoutQuota(): void
    {
        $verifier = new Verifier(self::pricing('2027-03-03T12:01:00Z'), self::pms());
        $stored = $verifier->stored([self::rule('2027-03-10', [ChangeField::FLOOR_PCT => -20])], self::rooms());

        $live = $verifier->live($stored, self::rooms(), self::pricing('2027-03-03T12:01:00Z')->listListings(), self::quota(false), self::at());

        self::assertSame(LiveRoute::CompareNow, $live[0]->liveRoute);
        self::assertSame(LiveStatus::Live, $live[0]->live?->status);
    }

    public function testWithoutAFreshCalendarTheQuotaDecides(): void
    {
        $verifier = new Verifier(self::pricing('2027-03-03T08:00:00Z'), self::pms());
        $stored = $verifier->stored([self::rule('2027-03-10', [ChangeField::FLOOR_PCT => -20])], self::rooms());
        $listings = self::pricing('2027-03-03T08:00:00Z')->listListings();

        self::assertSame(LiveRoute::Recalculate, $verifier->live($stored, self::rooms(), $listings, self::quota(true), self::at())[0]->liveRoute);
        $none = $verifier->live($stored, self::rooms(), $listings, self::quota(false), self::at())[0];
        self::assertSame(LiveRoute::NotConfirmedQuota, $none->liveRoute);
        self::assertNull($none->live);
    }

    public function testANightNotStoredIsNotCheckedLive(): void
    {
        $verifier = new Verifier(self::pricing('2027-03-03T12:01:00Z'), self::pms());
        $stored = $verifier->stored([self::rule('2027-03-11', [ChangeField::FLOOR_PCT => -20])], self::rooms());

        self::assertNull($verifier->live($stored, self::rooms(), [], self::quota(true), self::at())[0]->liveRoute);
    }

    /**
     * @param array<string, int> $sent
     */
    private static function rule(string $night, array $sent, string $room = 'Deluxe'): WrittenNight
    {
        return new WrittenNight(Provider::Pricing, $room, Days::date($night), WriteKind::Write, new NightFields($sent), NightFields::none(), self::at());
    }

    private static function pricing(?string $refreshedAt = null): InMemoryPricingConnector
    {
        return new InMemoryPricingConnector(
            [new ListingInfo('pricing-1', 'Deluxe', 600.0, 1000.0, 3000.0, 'EUR', new SyncStatus(true, null, $refreshedAt === null ? null : new DateTimeImmutable($refreshedAt)))],
            ['pricing-1' => [
                new PriceNight(Days::date('2027-03-09'), 900.0, 700.0, null, 2, 1, 4, false),
                new PriceNight(Days::date('2027-03-10'), 850.0, 800.0, null, 2, 1, 4, true),
                new PriceNight(Days::date('2027-03-11'), 900.0, 700.0, null, 2, 1, 4, false),
            ]],
            ['pricing-1' => [new DateOverride(Days::date('2027-03-10'), -20.0), new DateOverride(Days::date('2027-03-11'), -30.0)]],
        );
    }

    private static function pms(): InMemoryPmsConnector
    {
        return new InMemoryPmsConnector([], ['pms-1' => [new InventoryNight(Days::date('2027-03-10'), 3, 2, null, Restriction::None)]]);
    }

    private static function quota(bool $allows): ConfirmationQuota
    {
        return new class ($allows) implements ConfirmationQuota {
            public function __construct(private readonly bool $allows)
            {
            }

            public function allows(string $roomCode, DateTimeImmutable $now): bool
            {
                return $this->allows;
            }
        };
    }

    /**
     * @return array<string, RoomType>
     */
    private static function rooms(): array
    {
        return ['Deluxe' => EngineSample::deluxe(), 'Standard' => EngineSample::standard()];
    }

    private static function at(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::WRITTEN_AT);
    }
}
