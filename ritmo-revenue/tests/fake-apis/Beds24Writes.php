<?php

declare(strict_types=1);

namespace RitmoFakeApis;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The write side of the Beds24 API V2: POST /inventory/rooms/calendar sets, per range of nights
 * of a room, the availability, the stays, the restriction and the channel limits (kept as text);
 * availability is read back net of bookings. Any other write (a booking above all) answers 405,
 * and every write body is kept so a test can assert that a price or a booking never arrived.
 */
final class Beds24Writes
{
    private const FIELDS = ['from', 'to', 'numAvail', 'minStay', 'maxStay', 'override', 'channels'];

    public function __construct(private readonly Scenario $scenario, private readonly WriteWorld $world)
    {
    }

    public function handle(string $method, string $path, string $body): Reply
    {
        $data = json_decode($body, true);
        $this->world->logWrite($method, '/beds24/v2' . $path, $data);
        if ($method !== 'POST' || $path !== '/inventory/rooms/calendar') {
            return self::error(405, 'The simulator writes only the inventory calendar.');
        }
        if (!is_array($data) || $data === []) {
            return self::error(400, 'A list of rooms is required.');
        }
        $results = [];
        foreach ($data as $room) {
            $results[] = $this->room($room);
        }
        $this->world->save();

        return Reply::json(200, $results);
    }

    /**
     * @return array<string, mixed>
     */
    private function room(mixed $room): array
    {
        $roomId = is_array($room) ? (int) ($room['roomId'] ?? 0) : 0;
        if ($this->scenario->room($roomId) === null || !is_array($room['calendar'] ?? null)) {
            return ['success' => false, 'errors' => [['field' => 'roomId', 'message' => 'unknown room']]];
        }
        foreach ($room['calendar'] as $range) {
            if (!is_array($range) || array_diff(array_keys($range), self::FIELDS) !== []) {
                return ['success' => false, 'errors' => [['field' => 'calendar', 'message' => 'unknown field']]];
            }
        }
        foreach ($room['calendar'] as $range) {
            $values = array_diff_key($range, ['from' => true, 'to' => true]);
            $utc = new DateTimeZone('UTC');
            for ($day = new DateTimeImmutable($range['from'], $utc); $day->format('Y-m-d') <= $range['to']; $day = $day->modify('+1 day')) {
                $this->world->writeNight((string) $roomId, $day->format('Y-m-d'), $values);
            }
        }

        return ['success' => true, 'modified' => ['roomId' => $roomId, 'ranges' => count($room['calendar'])]];
    }

    private static function error(int $status, string $message): Reply
    {
        return Reply::json($status, ['success' => false, 'type' => 'error', 'code' => $status, 'error' => $message]);
    }
}
