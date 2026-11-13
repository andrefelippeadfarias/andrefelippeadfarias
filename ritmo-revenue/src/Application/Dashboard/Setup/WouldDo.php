<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Setup;

use Ritmo\Application\Grid\LastPlan;
use Ritmo\Application\Grid\PlanIndex;
use Ritmo\Application\Grid\PlannedNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Infrastructure\Db\Repository\RunRepository;

/**
 * "What the plugin would do" (wizard step 9): from the last completed shadow run, what it read, how
 * many nights it would change, up and down, the nearest changes, the nights the validator refused
 * and the nights it could not decide for lack of market prices.
 */
final class WouldDo
{
    public const NEAREST = 10;

    public function __construct(private readonly LastPlan $lastPlan)
    {
    }

    /**
     * @param array<string, RoomType> $rooms code => room type: a night without a date rule is compared with the listing's own minimum
     *
     * @return array{run: array<string, mixed>|null, nights: int, no_market: int, total: int, up: int, down: int, nearest: list<array{room: string, night: string, planned: PlannedNight}>, refused: list<array{room: string, night: string, planned: PlannedNight}>}
     */
    public function summary(string $propertyId, array $rooms = []): array
    {
        $run = null;
        foreach ($this->lastPlan->recentRuns($propertyId, 20) as $candidate) {
            if ($candidate['kind'] === 'shadow' && $candidate['status'] === RunRepository::COMPLETED) {
                $run = $candidate;
                break;
            }
        }
        $plan = $run === null ? PlanIndex::empty() : $this->lastPlan->ofRun($run)->withListing($rooms);
        $summary = is_array($run['summary'] ?? null) ? $run['summary'] : [];
        $changes = [];
        $refused = [];
        foreach ($plan->all() as $key => $planned) {
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
            'run' => $run,
            'nights' => self::count($summary, 'decide', 'nights'),
            'no_market' => self::count($summary, 'decide', 'no_market_data'),
            'total' => count($changes),
            'up' => $directions['up'] ?? 0,
            'down' => $directions['down'] ?? 0,
            'nearest' => array_slice($changes, 0, self::NEAREST),
            'refused' => array_slice($refused, 0, self::NEAREST),
        ];
    }

    /**
     * @param array<string, mixed> $summary the run's stage summaries
     */
    private static function count(array $summary, string $stage, string $key): int
    {
        $value = is_array($summary[$stage] ?? null) ? ($summary[$stage][$key] ?? 0) : 0;

        return is_int($value) ? max(0, $value) : 0;
    }
}
