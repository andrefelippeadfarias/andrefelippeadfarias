<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Db;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\PriceSampleRepository;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Unit\Infrastructure\Support\MigratedDatabase;

final class MarketRepositoriesTest extends TestCase
{
    private PdoDatabase $db;

    protected function setUp(): void
    {
        $this->db = MigratedDatabase::create();
    }

    public function testAnchorsAreKeptOnePerNightAndReadAsARange(): void
    {
        $anchors = new MarketAnchorRepository($this->db, new MovableClock());
        $first = new MarketAnchor(Days::date('2026-12-24'), 410.0, 520.5, 640.25, 810.0, 1200.0, 12, Days::date('2026-11-10'), AnchorOrigin::Measured);
        $second = new MarketAnchor(Days::date('2026-12-25'), 400.0, 500.0, 600.0, 800.0, 900.0, 0, null, AnchorOrigin::Fallback);

        $anchors->saveMany('inn-1', [$first, $second]);
        $anchors->save('inn-2', new MarketAnchor(Days::date('2026-12-24'), 1.0, 2.0, 3.0, 4.0, 5.0, 3, null, AnchorOrigin::Measured));

        self::assertEquals(['2026-12-24' => $first, '2026-12-25' => $second], $anchors->range('inn-1', Days::date('2026-12-01'), Days::date('2026-12-31')));
        self::assertEquals(['2026-12-25' => $second], $anchors->range('inn-1', Days::date('2026-12-25'), Days::date('2026-12-25')));
        self::assertSame([], $anchors->range('inn-1', Days::date('2027-01-01'), Days::date('2027-01-31')));
        self::assertEquals($first, $anchors->find('inn-1', Days::date('2026-12-24')));
        self::assertNull($anchors->find('inn-1', Days::date('2026-12-26')));

        $newer = new MarketAnchor(Days::date('2026-12-24'), 420.0, 530.0, 650.0, 820.0, 1300.0, 15, Days::date('2026-11-11'), AnchorOrigin::Measured);
        $anchors->save('inn-1', $newer);

        self::assertEquals($newer, $anchors->find('inn-1', Days::date('2026-12-24')));
        self::assertSame('3', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_market_anchor'));
    }

    public function testPriceSamplesAreAddedAndReadByNight(): void
    {
        $samples = new PriceSampleRepository($this->db);
        $seen = new DateTimeImmutable('2026-11-10T15:00:00Z');

        $first = $samples->add('inn-1', 3, Days::date('2026-12-24'), 612.33, $seen, 'csv');
        $samples->add('inn-1', 4, Days::date('2026-12-24'), 700.0, $seen->modify('+1 day'), 'manual', PricePlan::Discounted, false, 2);
        $samples->add('inn-1', 3, Days::date('2026-12-23'), 500.0, $seen);
        $samples->add('inn-2', 9, Days::date('2026-12-24'), 100.0, $seen);

        $rows = $samples->forNights('inn-1', Days::date('2026-12-23'), Days::date('2026-12-24'));
        self::assertSame([500.0, 612.33, 700.0], array_column($rows, 'price'));
        self::assertSame($first, $rows[1]['id']);
        self::assertSame(
            ['property_id' => 'inn-1', 'competitor_id' => 4, 'price' => 700.0, 'plan' => PricePlan::Discounted, 'available' => false, 'min_stay' => 2, 'source' => 'manual'],
            array_intersect_key($rows[2], array_flip(['property_id', 'competitor_id', 'price', 'plan', 'available', 'min_stay', 'source']))
        );
        self::assertSame(PricePlan::PublicRate, $rows[0]['plan']);
        self::assertTrue($rows[0]['available']);
        self::assertNull($rows[0]['min_stay']);
        self::assertSame('2026-12-23', Days::key($rows[0]['night']));
        self::assertSame('2026-11-10T15:00:00+00:00', $rows[0]['captured_at']->format(DATE_ATOM));

        $recent = $samples->forNights('inn-1', Days::date('2026-12-01'), Days::date('2026-12-31'), $seen->modify('+1 hour'));
        self::assertSame([700.0], array_column($recent, 'price'));

        self::assertSame(3, $samples->purgeCapturedBefore($seen->modify('+1 hour')));
        self::assertCount(1, $samples->forNights('inn-1', Days::date('2026-12-01'), Days::date('2026-12-31')));
    }

    public function testAPriceSampleNeedsAPositivePrice(): void
    {
        $samples = new PriceSampleRepository($this->db);

        $this->expectException(InvalidArgumentException::class);
        $samples->add('inn-1', 3, Days::date('2026-12-24'), 0.0, new DateTimeImmutable('2026-11-10T15:00:00Z'));
    }

    public function testSnapshotsKeepEveryCaptureAndAnswerWithTheLatest(): void
    {
        $snapshots = new InventorySnapshotRepository($this->db);
        $morning = new DateTimeImmutable('2026-11-11T10:53:00Z');
        $evening = new DateTimeImmutable('2026-11-11T22:53:00Z');
        self::assertNull($snapshots->lastCapturedAt('suite'));

        self::assertSame(2, $snapshots->store('suite', [
            new InventoryNight(Days::date('2026-12-24'), 2, 2, 14, Restriction::NoCheckout, 890.5),
            new InventoryNight(Days::date('2026-12-25'), 2),
        ], $morning, 41));
        $snapshots->store('suite', [new InventoryNight(Days::date('2026-12-24'), 1, 3, null, Restriction::None, 950.0)], $evening);
        $snapshots->store('loft', [new InventoryNight(Days::date('2026-12-24'), 0)], $evening);

        $latest = $snapshots->latest('suite', Days::date('2026-12-01'), Days::date('2026-12-31'));
        self::assertSame(['2026-12-24', '2026-12-25'], array_keys($latest));
        self::assertEquals(new InventoryNight(Days::date('2026-12-24'), 1, 3, null, Restriction::None, 950.0), $latest['2026-12-24']);
        self::assertEquals(new InventoryNight(Days::date('2026-12-25'), 2), $latest['2026-12-25']);

        $asOfNoon = $snapshots->latest('suite', Days::date('2026-12-24'), Days::date('2026-12-24'), new DateTimeImmutable('2026-11-11T12:00:00Z'));
        self::assertEquals(new InventoryNight(Days::date('2026-12-24'), 2, 2, 14, Restriction::NoCheckout, 890.5), $asOfNoon['2026-12-24']);
        self::assertSame([], $snapshots->latest('suite', Days::date('2026-12-24'), Days::date('2026-12-24'), new DateTimeImmutable('2026-11-11T00:00:00Z')));

        self::assertSame('2026-11-11T22:53:00+00:00', $snapshots->lastCapturedAt('suite')?->format(DATE_ATOM));
        self::assertSame('41', $this->db->selectValue('SELECT MAX(run_id) FROM wp_ritmo_snapshots'));
    }

    public function testStoringTheSameCaptureAgainReplacesItAndOldCapturesCanBePurged(): void
    {
        $snapshots = new InventorySnapshotRepository($this->db);
        $morning = new DateTimeImmutable('2026-11-11T10:53:00Z');
        $snapshots->store('suite', [new InventoryNight(Days::date('2026-12-24'), 2)], $morning);
        $snapshots->store('suite', [new InventoryNight(Days::date('2026-12-24'), 1)], $morning);
        $snapshots->store('suite', [new InventoryNight(Days::date('2026-12-24'), 0)], $morning->modify('+3 hours'));

        self::assertSame('2', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_snapshots'));
        self::assertSame(1, $snapshots->latest('suite', Days::date('2026-12-24'), Days::date('2026-12-24'), $morning)['2026-12-24']->available);

        self::assertSame(1, $snapshots->purgeCapturedBefore($morning->modify('+1 hour')));
        self::assertSame(0, $snapshots->latest('suite', Days::date('2026-12-24'), Days::date('2026-12-24'))['2026-12-24']->available);
    }
}
