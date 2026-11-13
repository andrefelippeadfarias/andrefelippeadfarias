<?php
/**
 * Seeds the throwaway site with what the Reports screen reads (after tests/e2e/seed.php): invented
 * bookings of the example property's rooms (ids, dates, statuses, channels and amounts only; no
 * guest data), a month's targets and one finished run whose report is stored as coded lines.
 * Test only.
 *
 *   php tests/e2e/seed-reports.php <wordpress folder> <property id>
 */

declare(strict_types=1);

use Ritmo\Application\Report\Store\RevenueTargetStore;
use Ritmo\Application\Report\Store\TargetRow;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;
use Ritmo\WordPress\Services;

if (PHP_SAPI !== 'cli' || $argc !== 3 || !is_file($argv[1] . '/wp-load.php')) {
    fwrite(STDERR, "Usage: php tests/e2e/seed-reports.php <wordpress folder> <property id>\n");
    exit(2);
}

require $argv[1] . '/wp-load.php';

$services = Services::instance();
$property = $services->properties()->find($argv[2]) ?? throw new RuntimeException('Seed the property first (tests/e2e/seed.php).');
$rooms = $services->roomTypes()->forProperty($property->id);
$now = $services->clock()->now();
$today = $services->clock()->today($property->timezone);
$bookings = new BookingRepository($services->db());
$channels = ['booking', 'airbnb', 'direct', 'expedia'];
for ($i = 0; $i < 24; ++$i) {
    $room = $rooms[$i % count($rooms)];
    $arrival = Days::add($today, ($i * 3) % 40 - 10);
    $created = $now->modify(sprintf('-%d hours', 6 + $i * 17));
    $status = $i % 7 === 3 ? BookingStatus::Cancelled : BookingStatus::Confirmed;
    $bookings->upsert('beds24', new BookingRecord(
        'E2E-' . (5000 + $i),
        $room->code,
        $arrival,
        Days::add($arrival, 1 + $i % 3),
        $status,
        $channels[$i % 4],
        $i % 2 === 0 ? 'API' : 'frontdesk',
        round(420.0 + 37.5 * $i, 2),
        $created,
        $created,
        $status === BookingStatus::Cancelled ? $created->modify('+3 hours') : null,
        $room->pmsRef,
    ), $now);
}
(new RevenueTargetStore($services->db()))->save($property->id, new TargetRow($today->format('Y-m'), ['minimum' => 30000.0, 'market_occupancy_ly' => 45.0, 'market_adr_ly' => 520.0, 'market_sold_percent' => 35.0]), 1, $now);
$runs = $services->runs();
$run = $runs->start($property->id, 'full', $property->mode, null, $now->modify('-1 hour'));
$runs->finish($run, 'completed', ['report' => ['items' => [
    ['code' => 'shadow_would_write', 'params' => ['items' => 12, 'pms' => 3]],
    ['code' => 'market_stale', 'params' => ['days' => 9]],
]]], $now->modify('-55 minutes'));
echo "seeded\n";
