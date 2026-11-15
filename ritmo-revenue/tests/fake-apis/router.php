<?php
/**
 * Fake Beds24 and PriceLabs APIs for tests, served by PHP's built-in server:
 *
 *   RITMO_FAKE_API_LOG=/tmp/fake-apis.log php -S 127.0.0.1:8099 tests/fake-apis/router.php
 *
 * Environment:
 *   RITMO_FAKE_API_LOG    file that receives one JSON line per request (method, path, query,
 *                         status); the state of the simulator is kept next to it
 *   RITMO_FAKE_ANTHROPIC_STATE  the state of the fake Claude API served under /anthropic
 *                         (AnthropicApi.php: its own log, script and cache; never in the log above)
 *   RITMO_FAKE_API_START  optional YYYY-MM-DD: moves the scenario so that its first night is that day
 *
 * The world it serves is tests/fixtures/api/scenario.json. Routes and the control plane are
 * described in Simulator.php. It answers reads only unless a test turns its writable world on
 * (POST /__writes), and it never calls anything.
 */

declare(strict_types=1);

namespace RitmoFakeApis;

require_once __DIR__ . '/Reply.php';
require_once __DIR__ . '/Journal.php';
require_once __DIR__ . '/Scenario.php';
require_once __DIR__ . '/Beds24Api.php';
require_once __DIR__ . '/PriceLabsApi.php';
require_once __DIR__ . '/WriteWorld.php';
require_once __DIR__ . '/PriceLabsWrites.php';
require_once __DIR__ . '/Beds24Writes.php';
require_once __DIR__ . '/Simulator.php';
require_once __DIR__ . '/AnthropicApi.php';

if (PHP_SAPI !== 'cli-server') {
    return;
}

$headers = [];
foreach ($_SERVER as $name => $value) {
    if (str_starts_with((string) $name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr((string) $name, 5)))] = (string) $value;
    }
}

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
if (str_starts_with($path, '/anthropic/')) {
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
    }
    (new AnthropicApi(getenv('RITMO_FAKE_ANTHROPIC_STATE') ?: sys_get_temp_dir() . '/ritmo-fake-anthropic.json'))
        ->handle(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), $path, $headers, (string) file_get_contents('php://input'))
        ->emit();

    return;
}
$simulator = new Simulator(
    Scenario::load(dirname(__DIR__) . '/fixtures/api/scenario.json', getenv('RITMO_FAKE_API_START') ?: null),
    new Journal(getenv('RITMO_FAKE_API_LOG') ?: sys_get_temp_dir() . '/ritmo-fake-apis.log')
);

$simulator->handle(
    strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
    (string) (parse_url($uri, PHP_URL_PATH) ?: '/'),
    (string) parse_url($uri, PHP_URL_QUERY),
    $headers,
    (string) file_get_contents('php://input')
)->emit();
