<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Journal;

use Ritmo\Application\Write\Schedule\WriteStep;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\PmsConnector;
use Ritmo\Contracts\PricingConnector;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Domain\Support\Days;

/**
 * Reads what is stored now on the nights of some steps (reads only, no quota): the date rules of
 * each listing (one GET per listing over its range) and the PMS calendar of the rooms.
 */
final class StateReader
{
    public function __construct(private readonly ?PricingConnector $pricing, private readonly ?PmsConnector $pms)
    {
    }

    /**
     * @param list<WriteStep> $steps
     *
     * @throws ConnectorException
     */
    public function forSteps(array $steps): StoredState
    {
        $pricing = $pms = [];
        foreach ($steps as $step) {
            if ($step->operation === WriteOperation::OverridesWrite || $step->operation === WriteOperation::OverridesDelete) {
                $pricing = self::add($pricing, $step->nights);
            } elseif ($step->operation === WriteOperation::PmsCalendar) {
                $pms = self::add($pms, $step->nights);
            }
        }

        return $this->read($pricing, $pms);
    }

    /**
     * @param array<string, list<string>> $pricing listing id => nights
     * @param array<string, list<string>> $pms     PMS room id => nights
     *
     * @throws ConnectorException
     */
    public function read(array $pricing, array $pms): StoredState
    {
        $rules = [];
        foreach ($pricing as $listing => $nights) {
            if ($nights === [] || $this->pricing === null) {
                continue;
            }
            $found = [];
            foreach ($this->pricing->readOverrides((string) $listing, Days::date(min($nights)), Days::date(max($nights))) as $override) {
                $found[Days::key($override->date)] = $override;
            }
            foreach ($nights as $night) {
                $rules[$listing][$night] = NightRule::ofOverride($found[$night] ?? null);
            }
        }
        $calendar = [];
        if ($pms !== [] && $this->pms !== null) {
            $all = array_merge(...array_values($pms));
            $read = $this->pms->readInventory(array_map('strval', array_keys($pms)), Days::date(min($all)), Days::date(max($all)));
            foreach ($read as $roomId => $nights) {
                foreach ($nights as $night) {
                    $calendar[(string) $roomId][Days::key($night->date)] = NightRule::ofInventory($night);
                }
            }
        }

        return new StoredState($rules, $calendar);
    }

    /**
     * @param array<string, list<string>> $map
     * @param array<string, list<string>> $nights
     *
     * @return array<string, list<string>>
     */
    private static function add(array $map, array $nights): array
    {
        foreach ($nights as $target => $dates) {
            $map[$target] = array_values(array_unique([...($map[$target] ?? []), ...$dates]));
        }

        return $map;
    }
}
