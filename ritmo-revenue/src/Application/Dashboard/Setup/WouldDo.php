<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Setup;

use Ritmo\Application\Grid\LastPlan;
use Ritmo\Application\Grid\PlannedNight;

/**
 * "What the plugin would do" (wizard step 9): from the last completed shadow run, how many nights
 * it would change, up and down, the nearest changes and the nights the validator refused.
 */
final class WouldDo
{
    public const NEAREST = 10;

    public function __construct(private readonly LastPlan $lastPlan)
    {
    }

    /**
     * @return array{run: array<string, mixed>|null, total: int, up: int, down: int, nearest: list<array{room: string, night: string, planned: PlannedNight}>, refused: list<array{room: string, night: string, planned: PlannedNight}>}
     */
    public function summary(string $propertyId): array
    {
        $shadow = array_values(array_filter($this->lastPlan->recentRuns($propertyId, 20), static fn (array $run): bool => $run['kind'] === 'shadow'));
        $last = $this->lastPlan->of($shadow);
        $changes = [];
        $refused = [];
        foreach ($last['plan']->all() as $key => $planned) {
            [$room, $night] = explode('|', $key, 2);
            $row = ['room' => $room, 'night' => $night, 'planned' => $planned];
            if ($planned->status === PlannedNight::WOULD_WRITE) {
                $changes[] = $row;
            } elseif ($planned->status === PlannedNight::REJECTED) {
                $refused[] = $row;
            }
        }
        usort($changes, static fn (array $a, array $b): int => [$a['night'], $a['room']] <=> [$b['night'], $b['room']]);
        usort($refused, static fn (array $a, array $b): int => [$a['night'], $a['room']] <=> [$b['night'], $b['room']]);
        $directions = array_count_values(array_map(static fn (array $c): string => (string) $c['planned']->direction(), $changes));

        return [
            'run' => $last['run'],
            'total' => count($changes),
            'up' => $directions['up'] ?? 0,
            'down' => $directions['down'] ?? 0,
            'nearest' => array_slice($changes, 0, self::NEAREST),
            'refused' => array_slice($refused, 0, self::NEAREST),
        ];
    }
}
