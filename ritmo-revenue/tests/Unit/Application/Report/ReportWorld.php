<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Report;

use DateTimeImmutable;
use Ritmo\Application\Report\ReportScope;
use Ritmo\Application\Report\ReportService;
use Ritmo\Application\Report\Store\RevenueTargetStore;
use Ritmo\Application\Report\Store\SnapshotStore;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Report\Config\ReportsConfig;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Migration\Migrations;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Infrastructure\Store\ArrayStore;

/**
 * An invented inn (two rooms, five units) in an in-memory database with every table and the
 * reports draft migration; bookings by id and channel only.
 */
final class ReportWorld
{
    public const NOW = '2026-11-13T13:00:00Z';

    public readonly PdoDatabase $db;
    public readonly ReportScope $scope;
    public readonly ReportService $service;
    public readonly RevenueTargetStore $targets;
    public readonly SnapshotStore $snapshots;
    public readonly BookingRepository $bookings;
    public readonly InventorySnapshotRepository $inventory;

    public function __construct(?ReportsConfig $config = null)
    {
        $this->db = self::database();
        $this->scope = new ReportScope(new Property('inn', 'Example Inn', 'America/Sao_Paulo', 'BRL'), [
            new RoomType('inn', 'STD', 'Standard', 3, '902001', 'L1', 480.0, 320.0, 1600.0, 320.0, ['view']),
            new RoomType('inn', 'GDN', 'Garden', 2, '902002', 'L2', 700.0, 450.0, 2400.0, 450.0),
        ]);
        $this->bookings = new BookingRepository($this->db);
        $this->inventory = new InventorySnapshotRepository($this->db);
        $this->targets = new RevenueTargetStore($this->db);
        $this->snapshots = new SnapshotStore($this->db);
        $this->service = new ReportService($this->bookings, $this->inventory, $this->targets, $this->snapshots, RuleSet::defaults(), $config ?? ReportsConfig::neutral());
    }

    public static function database(): PdoDatabase
    {
        $db = PdoDatabase::inMemory();
        (new Migrator($db, new ArrayStore(), Migrations::all()))->migrate();

        return $db;
    }

    public function book(string $ref, string $room, string $arrival, int $nights, float $total, string $created, BookingStatus $status = BookingStatus::Confirmed, string $channel = 'booking', ?string $cancelled = null): void
    {
        $this->bookings->upsert('beds24', new BookingRecord(
            $ref,
            $room,
            Days::date($arrival),
            Days::add(Days::date($arrival), $nights),
            $status,
            $channel,
            'API',
            $total,
            new DateTimeImmutable($created),
            new DateTimeImmutable($created),
            $cancelled === null ? null : new DateTimeImmutable($cancelled),
            $room === 'STD' ? '902001' : '902002',
        ), new DateTimeImmutable(self::NOW));
    }

    /**
     * @param array<string, int> $available night => units available
     */
    public function stock(string $room, array $available): void
    {
        $nights = [];
        foreach ($available as $night => $units) {
            $nights[] = new InventoryNight(Days::date($night), $units);
        }
        $this->inventory->store($room, $nights, new DateTimeImmutable(self::NOW));
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}
