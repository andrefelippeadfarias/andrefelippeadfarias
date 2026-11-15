<?php

declare(strict_types=1);

namespace Ritmo\Application\Ai;

use DateTimeImmutable;
use Ritmo\Domain\Ai\DraftContext;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;

/**
 * The data a request is drafted from, for one property: today and the last night an order may
 * cover, the currency, the rooms (code, name as a label, base price) and the periods of the
 * calendar with the path of each in the rules. Nothing about a booking or a guest.
 */
final class RequestData
{
    private function __construct()
    {
    }

    /**
     * @param list<RoomType>              $rooms
     * @param array<string, list<string>> $names rule field key => its label in the reader's language
     *
     * @return array{data: array<string, mixed>, context: DraftContext}
     */
    public static function of(Property $property, array $rooms, RuleSet $rules, DateTimeImmutable $today, string $request, array $names = []): array
    {
        $labels = [];
        foreach ($rooms as $room) {
            $labels[$room->code] = $room->name;
        }
        $context = new DraftContext($today, self::bases($rooms), $request, $rules->toArray(), $names, $labels);
        $periods = [];
        foreach (is_array($rules->toArray()['calendar']['holidays'] ?? null) ? $rules->toArray()['calendar']['holidays'] : [] as $index => $period) {
            if (is_array($period) && is_string($period['start'] ?? null) && is_string($period['end'] ?? null)) {
                $periods[] = [
                    'path' => 'calendar.holidays.' . $index,
                    'label' => mb_substr((string) ($period['name'] ?? ''), 0, 120),
                    'first' => $period['start'],
                    'last' => $period['end'],
                    'floor' => $period['floor'] ?? null,
                ];
            }
        }

        return ['data' => [
            'today' => Days::key($today),
            'last_night' => Days::key($context->lastNight()),
            'weekday_of_today' => Days::weekday($today),
            'currency' => $property->currency,
            'rooms' => array_map(static fn (RoomType $r): array => ['code' => $r->code, 'label' => mb_substr($r->name, 0, 120), 'base' => $r->base], $rooms),
            'periods' => $periods,
        ], 'context' => $context];
    }

    /**
     * @param list<RoomType> $rooms
     *
     * @return array<string, float>
     */
    private static function bases(array $rooms): array
    {
        $bases = [];
        foreach ($rooms as $room) {
            $bases[$room->code] = $room->base;
        }

        return $bases;
    }
}
