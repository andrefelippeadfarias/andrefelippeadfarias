<?php
/**
 * For tests/e2e/m5-licence.js: a property a person had moved to approval mode (recorded like the
 * Operations screen records it), then "Run now" through the site's own services, under whatever
 * licence the site has. Prints what happened as JSON: the licence standing, the mode before, the
 * mode the run started in, the mode after, the latest mode event and the run's status. Test only.
 *
 *   php tests/e2e/licence-run.php <wordpress folder> <property id>
 */

declare(strict_types=1);

use Ritmo\Application\Schedule\LaunchStatus;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\WordPress\Services;

if (PHP_SAPI !== 'cli' || $argc !== 3 || !is_file($argv[1] . '/wp-load.php')) {
    fwrite(STDERR, "Usage: php tests/e2e/licence-run.php <wordpress folder> <property id>\n");
    exit(2);
}

require $argv[1] . '/wp-load.php';

$services = Services::instance();
$stack = $services->stack();
$id = $argv[2];
$property = $stack->properties()->find($id);
if ($property === null) {
    fwrite(STDERR, "Unknown property $id\n");
    exit(1);
}

$stack->events()->record($id, OperationEventRepository::MODE, $property->mode->value, PropertyMode::Approval->value, 'End-to-end test: approval mode, set while the licence allowed it', 'user:1', $services->clock()->now());
$stack->properties()->setMode($id, PropertyMode::Approval);
$before = $stack->properties()->find($id)?->mode->value;

$result = $stack->runNow()->run($id, 120);
for ($i = 0; $i < 20 && $result->status === LaunchStatus::Running; ++$i) {
    $result = $stack->launcher()->continueOpen($stack->properties()->find($id) ?? $property, 120) ?? $result;
}
$run = $result->runId === null ? null : $stack->runs()->find($result->runId);
$event = $stack->events()->latest($id, OperationEventRepository::MODE, 1)[0] ?? null;

echo json_encode([
    'licence' => $services->licence()->verdict()->standing->value,
    'before' => $before,
    'run_mode' => $run === null ? null : $run['mode']->value,
    'status' => $result->status->value,
    'after' => $stack->properties()->find($id)?->mode->value,
    'event' => $event === null ? null : ['from' => $event['from'], 'to' => $event['to'], 'actor' => $event['actor']],
], JSON_THROW_ON_ERROR), "\n";
