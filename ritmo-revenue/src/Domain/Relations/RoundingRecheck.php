<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\Config\RelationSettings;
use Ritmo\Domain\Relations\Params\AtLeastParams;
use Ritmo\Domain\Relations\Params\BetweenMode;
use Ritmo\Domain\Relations\Params\BetweenParams;
use Ritmo\Domain\Relations\Params\PremiumParams;

/**
 * Checks the order of the rooms in currency AFTER their floors were turned into whole percents of
 * their own bases (one point is a different amount in each room, so a target set equal to its
 * reference can come out one point above it).
 *
 * Mode "warn" reports; mode "fix" also lowers a target above its high reference one point at a
 * time (never below the low reference nor below its own lowest percent) and raises a target below
 * what its reference requires. Discount bands and switches are exempt: they are already chosen on
 * the target's grid.
 */
final class RoundingRecheck
{
    /** @var list<RecheckFinding> */
    private array $findings = [];

    public function __construct(private readonly RelationGraph $graph, private readonly RelationSettings $settings)
    {
    }

    /**
     * Checks one night. Rooms with an item enter with their percents; rooms without one with a
     * fixed amount (their decided or current price).
     *
     * @param list<Relation>                                                  $relations the relations that hold
     *                                                                                   tonight, in resolution order
     * @param array<string, array{floor: int, ceiling: int|null, lowest?: int|null}> $percents  room => percents of its item
     * @param array<string, float>                                           $fixed     room => amount without an item
     *
     * @return array{findings: list<RecheckFinding>, percents: array<string, array{floor: int, ceiling: int|null, lowest?: int|null}>}
     */
    public function check(array $relations, NightType $type, array $percents, array $fixed): array
    {
        $this->findings = [];
        if ($this->settings->recheckMode === RecheckMode::Off) {
            return ['findings' => [], 'percents' => $percents];
        }
        foreach ($relations as $relation) {
            $room = $this->graph->room($relation->target);
            if ($room === null || !isset($percents[$relation->target])) {
                continue;
            }
            $params = $relation->params;
            if ($params instanceof BetweenParams && $params->mode === BetweenMode::Path) {
                $percents = $this->checkBetween($relation, $params, $room, $percents, $fixed);
            } elseif ($params instanceof AtLeastParams || $params instanceof PremiumParams) {
                $percents = $this->checkRequired($relation, $type, $room, $percents, $fixed);
            }
        }

        return ['findings' => $this->findings, 'percents' => $percents];
    }

    /**
     * @param array<string, array{floor: int, ceiling: int|null, lowest?: int|null}> $percents
     * @param array<string, float>                                           $fixed
     *
     * @return array<string, array{floor: int, ceiling: int|null, lowest?: int|null}>
     */
    private function checkBetween(Relation $relation, BetweenParams $params, RoomType $room, array $percents, array $fixed): array
    {
        $a = $this->amountOf($params->lowReference, $percents, $fixed);
        $b = $this->amountOf($params->highReference, $percents, $fixed);
        if ($a === null || $b === null) {
            return $percents;
        }
        $eps = $this->settings->recheckEpsilon;
        $low = min($a, $b);
        $high = max($a, $b);
        $target = $percents[$relation->target];
        $effective = Grid::amount($room, $target['floor']);
        if ($effective <= $high + $eps) {
            return $percents;
        }
        if ($this->settings->recheckMode !== RecheckMode::Fix) {
            $this->findings[] = new RecheckFinding(RecheckFinding::INVERSION, $relation->id, $relation->target, $effective, $high, $target['floor']);

            return $percents;
        }
        $lowest = $target['lowest'] ?? PHP_INT_MIN;
        while (Grid::amount($room, $target['floor']) > $high + $eps
            && $target['floor'] - 1 >= $lowest
            && Grid::amount($room, $target['floor'] - 1) >= $low - $eps) {
            --$target['floor'];
        }
        if ($target['ceiling'] !== null) {
            $target['ceiling'] = max($target['floor'], min($target['ceiling'], Grid::ceilingPercent($room, $high)));
        }
        $solved = Grid::amount($room, $target['floor']) <= $high + $eps;
        $code = $solved ? RecheckFinding::INVERSION : RecheckFinding::UNSOLVABLE;
        $this->findings[] = new RecheckFinding($code, $relation->id, $relation->target, $effective, $high, $percents[$relation->target]['floor'], $target['floor']);
        $percents[$relation->target] = $target;

        return $percents;
    }

    /**
     * @param array<string, array{floor: int, ceiling: int|null, lowest?: int|null}> $percents
     * @param array<string, float>                                           $fixed
     *
     * @return array<string, array{floor: int, ceiling: int|null, lowest?: int|null}>
     */
    private function checkRequired(Relation $relation, NightType $type, RoomType $room, array $percents, array $fixed): array
    {
        $need = $this->required($relation, $type, $percents, $fixed);
        $target = $percents[$relation->target];
        $effective = Grid::amount($room, $target['floor']);
        if ($need === null || $effective >= $need - $this->settings->recheckEpsilon) {
            return $percents;
        }
        if ($this->settings->recheckMode !== RecheckMode::Fix) {
            $this->findings[] = new RecheckFinding(RecheckFinding::BELOW_REQUIRED, $relation->id, $relation->target, $effective, $need, $target['floor']);

            return $percents;
        }
        $target['floor'] = Grid::floorPercent($room, $need);
        if ($target['ceiling'] !== null) {
            $target['ceiling'] = max($target['ceiling'], $target['floor']);
        }
        $this->findings[] = new RecheckFinding(RecheckFinding::BELOW_REQUIRED, $relation->id, $relation->target, $effective, $need, $percents[$relation->target]['floor'], $target['floor']);
        $percents[$relation->target] = $target;

        return $percents;
    }

    /**
     * What the reference (or the dearest member of the set) requires of the target, without epsilon.
     *
     * @param array<string, array{floor: int, ceiling: int|null, lowest?: int|null}> $percents
     * @param array<string, float>                                           $fixed
     */
    private function required(Relation $relation, NightType $type, array $percents, array $fixed): ?float
    {
        $params = $relation->params;
        if ($params instanceof AtLeastParams) {
            $reference = $this->amountOf($params->reference, $percents, $fixed);

            return $reference === null ? null : $params->margin->applyTo($reference);
        }
        if (!$params instanceof PremiumParams) {
            return null;
        }
        $values = [];
        foreach ($this->graph->members($relation) as $member) {
            $value = $this->amountOf($member, $percents, $fixed);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values === [] ? null : max($values) * (1 + $params->premiumFor($type));
    }

    /**
     * @param array<string, array{floor: int, ceiling: int|null, lowest?: int|null}> $percents
     * @param array<string, float>                                           $fixed
     */
    private function amountOf(string $code, array $percents, array $fixed): ?float
    {
        $room = $this->graph->room($code);
        if ($room !== null && isset($percents[$code])) {
            return Grid::amount($room, $percents[$code]['floor']);
        }

        return $fixed[$code] ?? null;
    }
}
