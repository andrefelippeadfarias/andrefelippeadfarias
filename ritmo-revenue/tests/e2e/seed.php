<?php
/**
 * Seeds the throwaway site of bin/wp-test-site.sh with the invented property the simulator
 * serves (tests/fixtures/api/scenario.json): the property, its three room types mapped to the
 * simulator's room and listing ids, the neutral preset of the plugin and competitor prices for
 * the sixty nights from today. Test only; it goes through the same setup service as the admin.
 *
 *   php tests/e2e/seed.php <wordpress folder>
 */

declare(strict_types=1);

use Ritmo\Application\Dashboard\Setup\SafetyValues;
use Ritmo\Application\Dashboard\Setup\SetupState;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Support\Days;
use Ritmo\WordPress\Services;

if (PHP_SAPI !== 'cli' || $argc !== 2 || !is_file($argv[1] . '/wp-load.php')) {
    fwrite(STDERR, "Usage: php tests/e2e/seed.php <wordpress folder>\n");
    exit(2);
}

require $argv[1] . '/wp-load.php';

const E2E_PROPERTY = 'example-inn';
const E2E_TIMEZONE = 'America/Sao_Paulo';

$root = dirname(__DIR__, 2);
$scenario = json_decode((string) file_get_contents($root . '/tests/fixtures/api/scenario.json'), true, 32, JSON_THROW_ON_ERROR);
$setup = Services::instance()->setup();

$preset = $setup->importPreset((string) file_get_contents($root . '/presets/default.json'));
// The operations pass switches to approval right after the two shadow runs: no minimum of shadow
// runs on this test site (the neutral rules ask for three).
$rules = Services::instance()->ruleSets()->current()?->toArray() ?? [];
$rules['modes']['min_shadow_runs'] = 0;
Services::instance()->ruleSets()->save(RuleSet::fromArray($rules), 'End-to-end test: approval without waiting for shadow runs');
$setup->createProperty(['id' => E2E_PROPERTY, 'name' => $scenario['beds24']['property']['name'], 'timezone' => E2E_TIMEZONE, 'currency' => $scenario['currency']]);

$codes = [];
foreach ($scenario['rooms'] as $index => $room) {
    $code = ['STD', 'GDN', 'MST'][$index];
    $codes[] = $code;
    $setup->saveRoomType([
        'property_id' => E2E_PROPERTY,
        'code' => $code,
        'name' => $room['name'],
        'units' => (string) $room['units'],
        'pms_ref' => (string) $room['room_id'],
        'pricing_ref' => $scenario['beds24']['property']['id'] . '___' . $room['room_id'],
        'base' => (string) $room['base'],
        'min' => (string) $room['min'],
        'max' => (string) $room['max'],
        'reference_min' => (string) round($room['min'] * 0.9),
        'tags' => '',
    ]);
}

// The safety limits as step 7 of the setup wizard confirms them (a condition of the approval mode,
// void once a safety value or a room's reference minimum changes).
Services::instance()->store()->set(SetupState::KEY, [
    'safety_confirmed_at' => gmdate(DATE_ATOM),
    'safety_confirmed_by' => 1,
    'safety_fingerprint' => SafetyValues::fingerprint(Services::instance()->ruleSets()->current() ?? RuleSet::defaults(), Services::instance()->roomTypes()->all()),
]);

// Six competitor prices a night: weekdays around 400-700, weekends 30% higher.
$today = Services::instance()->clock()->today(E2E_TIMEZONE);
$lines = ['date,price'];
for ($day = 0; $day < $scenario['nights']; ++$day) {
    $night = Days::add($today, $day);
    $weekend = in_array(Days::weekday($night), [4, 5], true);
    foreach ([380, 420, 470, 520, 610, 720] as $price) {
        $lines[] = Days::key($night) . ',' . ($weekend ? round($price * 1.3) : $price);
    }
}
$samples = $setup->importSamples(E2E_PROPERTY, implode("\n", $lines));

printf("Seeded %s: rules version %d (then the operations tweak), room types %s, %d prices for %d nights.\n", E2E_PROPERTY, $preset['rules_version'], implode(', ', $codes), $samples['samples'], $samples['nights']);
