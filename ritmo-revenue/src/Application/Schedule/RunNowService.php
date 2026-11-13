<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

use OutOfBoundsException;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;

/**
 * "Run now" (operations spec 3.6, S11): a person runs a property whatever its schedule says. A
 * pause stops only the scheduled slots, so a paused property still runs here. The run goes
 * through the same launcher, and so the same property run lock, as the scheduled runs: a run in
 * progress is continued instead of starting a second one, and a run held by another request
 * answers busy. Under the emergency switch the run is a forced shadow run (it reads and watches,
 * it writes nothing). The caller checks the capability and the nonce first.
 */
final class RunNowService
{
    public const DEFAULT_BUDGET_SECONDS = 20;

    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly RunLauncher $launcher,
        private readonly EmergencySwitch $switch,
    ) {
    }

    /**
     * @throws OutOfBoundsException when the property does not exist
     */
    public function run(string $propertyId, int $budgetSeconds = self::DEFAULT_BUDGET_SECONDS): LaunchResult
    {
        $property = $this->property($propertyId);
        $open = $this->launcher->continueOpen($property, $budgetSeconds);
        if ($open !== null && $open->failure !== FailureKind::DayChanged) {
            return $open;
        }

        // No run in progress, or the open one was of an earlier day and was just closed.
        return $this->launcher->start($property, RunKind::Manual, $this->switch->isEngaged(), $budgetSeconds);
    }

    private function property(string $propertyId): Property
    {
        return $this->properties->find($propertyId) ?? throw new OutOfBoundsException(sprintf('Unknown property "%s".', $propertyId));
    }
}
