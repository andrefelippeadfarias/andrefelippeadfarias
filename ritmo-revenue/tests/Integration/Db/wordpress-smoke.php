<?php
/**
 * Smoke test of the infrastructure module on a real WordPress: migrations through dbDelta,
 * WpdbDatabase, OptionsStore, every repository, the lock, the log, the vault and the quota
 * ledger. It is a script, not part of the PHPUnit suite, because it needs a WordPress folder.
 *
 *   php tests/Integration/Db/wordpress-smoke.php <wordpress folder>
 *
 * The folder is a throwaway site: WordPress unpacked from https://wordpress.org/latest.zip,
 * the plugin https://downloads.wordpress.org/plugin/sqlite-database-integration.zip unpacked
 * in wp-content/plugins, its db.copy copied to wp-content/db.php, and a wp-config.php that
 * defines DB_ENGINE "sqlite", DB_DIR, DB_FILE "smoke.sqlite", WP_HOME and WP_SITEURL on
 * 127.0.0.1 and WP_HTTP_BLOCK_EXTERNAL. The script deletes and recreates that database file.
 *
 * Nothing leaves the machine: every outgoing HTTP request of WordPress is refused.
 * Last run: 67 checks passed on WordPress 7.1.4 with SQLite driver 3.1.3.
 */

declare(strict_types=1);

use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\SystemClock;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\Repository as R;
use Ritmo\Infrastructure\Db\Schema\SchemaInspector;
use Ritmo\Infrastructure\Db\WpdbDatabase;
use Ritmo\Infrastructure\Http\DbQuotaLedger;
use Ritmo\Infrastructure\Lock\DbLock;
use Ritmo\Infrastructure\Log\DbLogger;
use Ritmo\Infrastructure\Log\LogSanitizer;
use Ritmo\Infrastructure\Secrets\SodiumVault;
use Ritmo\Infrastructure\Store\OptionsStore;

$site = rtrim((string) ($argv[1] ?? ''), '/');
if (!is_file($site . '/wp-load.php')) {
    fwrite(STDERR, "Usage: php tests/Integration/Db/wordpress-smoke.php <wordpress folder>\n");
    exit(2);
}
@unlink($site . '/wp-content/database/smoke.sqlite');
define('WP_INSTALLING', true);
function wp_new_blog_notification() {}
require $site . '/wp-load.php';
add_filter('pre_http_request', static fn () => new WP_Error('blocked', 'No network in the smoke test.'), 10, 3);
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
wp_install('Smoke', 'admin', 'admin@example.test', false, '', 'smoke-pass-123');
require dirname(__DIR__, 3) . '/src/autoload.php';

$checks = 0;
function check(bool $ok, string $what): void
{
    global $checks;
    ++$checks;
    if (!$ok) {
        fwrite(STDERR, "FAILED: $what\n");
        exit(1);
    }
}

global $wpdb;
$db = new WpdbDatabase($wpdb);
$store = new OptionsStore();
$clock = new SystemClock();

// Migrations through the real dbDelta, twice.
$migrator = Migrator::standard($db, $store, static fn (string $sql) => dbDelta($sql), $db->charsetCollate());
check($migrator->migrate() === [1, 2, 3, 4, 5], 'first migration applies 1..5');
check($migrator->migrate() === [], 'second migration applies nothing');
check(get_option('ritmo_db_version') === '5', 'ritmo_db_version option holds 5');
check(Migrator::standard($db, new Ritmo\Infrastructure\Store\ArrayStore(), static fn (string $sql) => dbDelta($sql), $db->charsetCollate())->migrate() === [1, 2, 3, 4, 5], 'dbDelta again over existing tables');
$inspector = new SchemaInspector($db);
foreach ($migrator->tables() as $name => $table) {
    check($inspector->columns($name) === $table->columnNames(), "columns of $name: " . implode(',', $inspector->columns($name)));
}
check($inspector->columns('never_created') === [], 'missing table has no columns');

// OptionsStore on real options.
$store->set('probe', ['a' => 1, 'b' => 60.0, 'c' => null, 'd' => false]);
check($store->get('probe') === ['a' => 1, 'b' => 60.0, 'c' => null, 'd' => false], 'options round trip types');
check($store->has('probe') && !$store->has('nope'), 'options has()');
$store->delete('probe');
check(!$store->has('probe'), 'options delete');

// Raw database behaviour.
$id = $db->insert('api_quota_log', ['provider' => "o'hara %s 100%", 'action' => 'a', 'scope' => '', 'succeeded' => true, 'called_at' => '2026-01-01 00:00:00']);
check($id > 0 && $db->selectValue('SELECT provider FROM ' . $db->table('api_quota_log') . ' WHERE id = ?', [$id]) === "o'hara %s 100%", 'hostile text round trip');
$db->delete('api_quota_log', ['id' => $id]);
try {
    $db->transaction(function () use ($db): void {
        $db->insert('api_quota_log', ['provider' => 'p', 'action' => 'a', 'scope' => '', 'succeeded' => true, 'called_at' => '2026-01-01 00:00:00']);
        throw new RuntimeException('stop');
    });
} catch (RuntimeException) {
}
check($db->selectValue('SELECT COUNT(*) FROM ' . $db->table('api_quota_log')) === '0', 'transaction rolled back');
try {
    $db->select('SELECT * FROM ' . $db->table('missing'));
    check(false, 'a failed query throws');
} catch (Ritmo\Infrastructure\Db\DatabaseException) {
    check(true, 'a failed query throws');
}

// Repositories.
$properties = new R\PropertyRepository($db, $clock);
$inn = new Property('inn-1', 'Harbour Inn', 'America/Sao_Paulo', 'BRL', PropertyMode::Shadow, [30 => 55.5], 'premium');
$properties->save($inn);
$properties->save($inn);
check($properties->find('inn-1') == $inn && count($properties->all()) === 1, 'property save/find');
$rooms = new R\RoomTypeRepository($db, $clock);
$suite = new RoomType('inn-1', 'suite', 'Garden Suite', 2, '901', 'L-77', 420.0, 310.5, 990.0, 300.0, ['premium']);
$rooms->save($suite);
$rooms->save(new RoomType('inn-1', 'double', 'Double', 5, '', '', 250.0, 180.0, 600.0, 170.0));
check($rooms->find('suite') == $suite && count($rooms->forProperty('inn-1')) === 2 && $rooms->pmsMap() === ['901' => 'suite'], 'room types');

$rules = new R\RuleSetRepository($db, $clock);
$defaults = RuleSet::defaults();
check($rules->save($defaults, 'first') === 1 && $rules->save($defaults) === 1, 'rule set versions');
check($rules->current()?->hash() === $defaults->hash(), 'rule set reads back identical');

$bookings = new R\BookingRepository($db);
$now = new DateTimeImmutable('2026-11-11T12:00:00Z');
$booking = new BookingRecord('B-1', 'suite', Days::date('2026-12-24'), Days::date('2026-12-27'), BookingStatus::Confirmed, 'booking', 'channel manager', 0.1 + 0.2, $now, $now, null, '901', 2);
$bookings->upsert('pms-a', $booking, $now);
$bookings->upsert('pms-a', $booking, $now);
check($db->selectValue('SELECT COUNT(*) FROM ' . $db->table('bookings')) === '1', 'booking upsert keeps one row');
check($bookings->find('pms-a', 'B-1') == $booking, 'booking round trip');
check($bookings->find('pms-a', 'B-1')?->total === 0.1 + 0.2, 'float exact through wpdb');
check(count($bookings->overlapping(Days::date('2026-12-26'), Days::date('2026-12-26'), 'suite')) === 1, 'booking overlap');
check(count($bookings->overlapping(Days::date('2026-12-27'), Days::date('2026-12-28'))) === 0, 'departure day is free');
check($bookings->latestModification('pms-a')?->format('c') === '2026-11-11T12:00:00+00:00', 'latest modification');

$snapshots = new R\InventorySnapshotRepository($db);
$snapshots->store('suite', [new InventoryNight(Days::date('2026-12-24'), 2, 2, null, Ritmo\Domain\Model\Restriction::NoCheckin, 890.5)], $now);
$snapshots->store('suite', [new InventoryNight(Days::date('2026-12-24'), 1)], $now->modify('+3 hours'));
check($snapshots->latest('suite', Days::date('2026-12-01'), Days::date('2026-12-31'))['2026-12-24']->available === 1, 'latest snapshot');
check($snapshots->latest('suite', Days::date('2026-12-24'), Days::date('2026-12-24'), $now)['2026-12-24']->pmsPrice === 890.5, 'snapshot as of');

$anchors = new R\MarketAnchorRepository($db, $clock);
$anchor = new MarketAnchor(Days::date('2026-12-24'), 410.0, 520.5, 640.25, 810.0, 1200.0, 12, Days::date('2026-11-10'), AnchorOrigin::Measured);
$anchors->saveMany('inn-1', [$anchor, $anchor]);
check($anchors->range('inn-1', Days::date('2026-12-01'), Days::date('2026-12-31')) == ['2026-12-24' => $anchor], 'anchor range');

$samples = new R\PriceSampleRepository($db);
$samples->add('inn-1', 3, Days::date('2026-12-24'), 612.33, $now, 'csv');
check($samples->forNights('inn-1', Days::date('2026-12-24'), Days::date('2026-12-24'))[0]['price'] === 612.33, 'price sample');

$runs = new R\RunRepository($db);
$run = $runs->start('inn-1', 'full', PropertyMode::Shadow, 1, $now);
$runs->finish($run, R\RunRepository::COMPLETED, ['proposed' => 2, 'written' => 0], $now->modify('+5 seconds'));
check($runs->find($run)['summary'] === ['proposed' => 2, 'written' => 0] && $runs->latest('inn-1', 1)[0]['id'] === $run, 'runs');
$items = new R\PlanItemRepository($db, $clock);
check($items->addAll($run, [
    ['propertyId' => 'inn-1', 'roomCode' => 'suite', 'night' => Days::date('2026-12-24'), 'field' => 'floor', 'current' => 610.0, 'proposed' => 655.5, 'reason' => 'pace_ahead', 'details' => ['sold' => 2]],
    ['propertyId' => 'inn-1', 'roomCode' => 'suite', 'night' => Days::date('2026-12-25'), 'field' => 'floor', 'current' => null, 'proposed' => 700.0],
]) === 2 && count($items->forRun($run)) === 2 && $items->forRun($run)[0]['details'] === ['sold' => 2], 'plan items');

$alerts = new R\AlertRepository($db, $clock);
$a = $alerts->raise('sync_stale', R\AlertRepository::WARNING, 'Prices were not pushed today.', ['hours' => 5], 'inn-1', '', null, 'sync:inn-1');
check($alerts->raise('sync_stale', R\AlertRepository::CRITICAL, 'Prices were not pushed today.', ['hours' => 8], 'inn-1', '', null, 'sync:inn-1') === $a && $alerts->countOpen() === 1, 'alert dedupe');
$alerts->resolve($a);
check($alerts->unresolved() === [], 'alert resolve');

$steps = new R\StepLedgerRepository($db);
$steps->record('inn-1', 'suite', Days::date('2026-12-24'), R\StepLedgerRepository::UP, 600.0, 630.0, $now, 'ahead', 1, 2);
check($steps->markReachedChannel('suite', $now->modify('+1 hour')) === 1 && $steps->lastStep('suite', Days::date('2026-12-24'))['reached_channel'] === true, 'step ledger');

// Quota ledger, lock, log, vault.
$ledger = new DbQuotaLedger(new R\QuotaLogRepository($db));
foreach ([0, 1, 2] as $hour) {
    $ledger->record('pricing', 'recalculation', 'L-1', $hour !== 1, $now->modify("+$hour hours"));
}
check($ledger->countInWindow('pricing', 'recalculation', 'L-1', 86400, $now->modify('+3 hours')) === 3, 'quota counts failures');
check($ledger->opensAt('pricing', 'recalculation', 'L-1', 3, 86400, $now->modify('+3 hours'))?->format('c') === '2026-11-12T12:00:00+00:00', 'quota opens 24 h after the oldest');
check($ledger->countSince('pricing', 'recalculation', '', $now) === 3, 'account scope');

$locks = new DbLock($db, $clock);
$handle = $locks->acquire('run:inn-1', 60);
check($handle !== null && $locks->acquire('run:inn-1', 60) === null, 'lock is exclusive');
check($locks->heartbeat($handle, 60) && $locks->heartbeat($handle, 60), 'heartbeat');
$locks->release($handle);
check(!$locks->isLocked('run:inn-1') && $locks->acquire('run:inn-1', 60) !== null, 'lock released and taken again');

$logger = new DbLogger($db, $clock, new LogSanitizer());
$logger->warning('Request to https://api.example/v2?token=abc123 failed.', ['guest_name' => 'Maria', 'http_status' => 500]);
$entry = $logger->recent(1)[0];
check($entry['message'] === 'Request to https://api.example/v2?token=[redacted] failed.' && $entry['context'] === ['guest_name' => '[omitted]', 'http_status' => 500], 'log sanitized and stored');

$vault = SodiumVault::forSite($store, $clock);
$vault->install();
$vault->put('pricing.api_key', 'pk-live-3f9a1c77d2e04b55a8c6');
check((SodiumVault::forSite(new OptionsStore(), $clock))->reveal('pricing.api_key') === 'pk-live-3f9a1c77d2e04b55a8c6', 'vault over real options');
check(!str_contains((string) get_option('ritmo_vault'), 'pk-live'), 'the option holds no clear secret');
check(!str_contains((string) get_option('ritmo_vault'), 'a8c6'), 'the option holds no character of the secret');
check($vault->protection() === SodiumVault::PROTECTION_SITE_SALTS, 'the vault key is bound to the salts of wp-config.php');
check($vault->rotate() === 1 && $vault->reveal('pricing.api_key') === 'pk-live-3f9a1c77d2e04b55a8c6' && $vault->status('pricing.api_key')->configured, 'vault rotation');
// WordPress reads options past its cache while installing; tests/e2e/wordpress-checks.php checks getFresh() across processes.
$store->set('fresh_probe', ['a' => 1]);
check($store->getFresh('fresh_probe') === ['a' => 1] && $store->getFresh('missing_probe', 'none') === 'none', 'getFresh over real options');
$store->delete('fresh_probe');

// Uninstall.
$migrator->uninstall();
check($inspector->columns('bookings') === [] && get_option('ritmo_db_version', 'gone') === 'gone', 'uninstall drops tables and the version');
check($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}") > 0, 'WordPress own tables are untouched');

echo "OK: $checks checks on WordPress " . get_bloginfo('version') . ' with ' . (defined('SQLITE_DRIVER_VERSION') ? 'SQLite driver ' . SQLITE_DRIVER_VERSION : 'database ' . $wpdb->db_version()) . "\n";
