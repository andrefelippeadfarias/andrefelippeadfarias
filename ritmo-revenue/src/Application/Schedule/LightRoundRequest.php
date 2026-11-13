<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

use DateTimeImmutable;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Operations\LightRunConfig;
use Ritmo\Domain\Operations\Movement;

/**
 * What a light run knows after its reads, to scope its work.
 */
final class LightRoundRequest
{
    /**
     * @param list<string>                                 $rooms         room codes of the property
     * @param list<Movement>                               $movements     the movement of this run's read
     * @param array<string, array<string, InventoryNight>> $inventory     room code => 'YYYY-MM-DD' => night, read by this run
     * @param DateTimeImmutable                            $readStartedAt when this run started reading (earlier inventory reads are "before")
     * @param array<string, list<DateTimeImmutable>>       $manualChanges room code => nights changed by hand, found by this run
     * @param int                                          $newOverbooked overbooked nights this run alerted for the first time
     */
    public function __construct(
        public readonly string $propertyId,
        public readonly array $rooms,
        public readonly DateTimeImmutable $today,
        public readonly int $horizonDays,
        public readonly array $movements,
        public readonly array $inventory,
        public readonly DateTimeImmutable $readStartedAt,
        public readonly array $manualChanges,
        public readonly int $newOverbooked,
        public readonly LightRunConfig $config,
    ) {
    }
}
