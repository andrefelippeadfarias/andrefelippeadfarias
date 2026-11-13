<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules\Orders;

use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Orders\OrderRelease;
use Ritmo\Domain\Orders\OrderType;
use Ritmo\Domain\Orders\OwnerOrder;
use Ritmo\Domain\Support\Days;

/**
 * Turns the fields of the order form (spec 05, 5.8) into an owner order row, field by field,
 * with every problem named by its form field. The order is then read by OrderReader as any
 * stored order.
 */
final class OrderForm
{
    /** The types a person may create; "manual" is created only by the plugin. */
    public const TYPES = ['fixed_price', 'floor', 'target', 'rule', 'restriction', 'follow_up'];

    /** Releases that need ritmo_manage_safety and the typed property id. */
    public const SAFETY_RELEASES = ['listing', 'below_standard_floor', 'above_weekly_rise_limit'];

    /** Releases each type may carry. */
    private const RELEASES = [
        'floor' => ['below_standard_floor'],
        'rule' => ['fixed_price', 'below_standard_floor', 'above_weekly_rise_limit', 'stay', 'orphan_stay', 'arrival_departure', 'discount_band', 'listing'],
        'restriction' => ['stay', 'orphan_stay', 'arrival_departure'],
    ];

    public const NOTE_MAX = 500;

    private const MAX_NIGHTS = 366;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $fields    type, rooms (list or ["*"]), from, to, nights (list), floors (room => amount text), percentile, releases (list), note
     * @param list<string>         $roomCodes the rooms of the property
     *
     * @return array<string, mixed> the order row (without id)
     *
     * @throws InvalidConfig naming the form fields that do not read
     */
    public static function toOrder(array $fields, array $roomCodes, float $defaultPercentile): array
    {
        $errors = [];
        $type = is_string($fields['type'] ?? null) && in_array($fields['type'], self::TYPES, true) ? $fields['type'] : null;
        if ($type === null) {
            $errors[] = new ValidationError('type', ValidationError::CHOICE, 'choose what the order does');
        }
        $rooms = self::rooms($fields['rooms'] ?? [], $roomCodes, $errors);
        [$from, $to, $nights] = self::nights($fields, $errors);
        $order = ['type' => $type ?? 'follow_up', 'rooms' => $rooms, 'from' => $from, 'to' => $to, 'note' => trim((string) ($fields['note'] ?? ''))];
        if ($nights !== []) {
            $order['nights'] = $nights;
        }
        if (in_array($type, ['floor', 'target'], true)) {
            $order['floors'] = self::floors((array) ($fields['floors'] ?? []), $rooms === [OwnerOrder::ALL_ROOMS] ? $roomCodes : $rooms, $type === 'floor', $errors);
        }
        if ($type === 'target') {
            $order['percentile'] = self::percentile($fields['percentile'] ?? '', $defaultPercentile, $errors);
        }
        $releases = array_values(array_unique(array_filter((array) ($fields['releases'] ?? []), 'is_string')));
        foreach ($releases as $release) {
            if (!in_array($release, self::RELEASES[$type] ?? [], true) || OrderRelease::tryFrom($release) === null) {
                $errors[] = new ValidationError('releases', ValidationError::CHOICE, 'this kind of order cannot release that');
            }
        }
        if ($type === 'fixed_price') {
            $releases = ['fixed_price'];
        }
        $order['releases'] = $releases;
        if ($errors !== []) {
            throw new InvalidConfig('owner order', $errors);
        }

        return $order;
    }

    /**
     * @param list<ValidationError> $errors
     *
     * @return list<string>
     */
    private static function rooms(mixed $value, array $roomCodes, array &$errors): array
    {
        $rooms = array_values(array_unique(array_filter(is_array($value) ? $value : [], 'is_string')));
        if (in_array(OwnerOrder::ALL_ROOMS, $rooms, true)) {
            return [OwnerOrder::ALL_ROOMS];
        }
        if ($rooms === [] || array_diff($rooms, $roomCodes) !== []) {
            $errors[] = new ValidationError('rooms', ValidationError::REQUIRED, 'choose one room or more of this property');
        }

        return $rooms;
    }

    /**
     * @param array<string, mixed>  $fields
     * @param list<ValidationError> $errors
     *
     * @return array{0: string, 1: string, 2: list<string>}
     */
    private static function nights(array $fields, array &$errors): array
    {
        $nights = array_values(array_unique(array_filter((array) ($fields['nights'] ?? []), static fn (mixed $n): bool => is_string($n) && $n !== '')));
        if ($nights !== []) {
            if (array_filter($nights, static fn (string $n): bool => !Days::isValid($n)) !== [] || count($nights) > self::MAX_NIGHTS) {
                $errors[] = new ValidationError('nights', ValidationError::FORMAT, 'each night is a date');

                return ['', '', []];
            }
            sort($nights);

            return [$nights[0], $nights[count($nights) - 1], $nights];
        }
        $from = trim((string) ($fields['from'] ?? ''));
        $to = trim((string) ($fields['to'] ?? ''));
        if (!Days::isValid($from)) {
            $errors[] = new ValidationError('from', ValidationError::REQUIRED, 'give the first night');
        }
        if (!Days::isValid($to)) {
            $errors[] = new ValidationError('to', ValidationError::REQUIRED, 'give the last night');
        }
        if (Days::isValid($from) && Days::isValid($to)) {
            $count = Days::diff(Days::date($from), Days::date($to));
            if ($count < 0) {
                $errors[] = new ValidationError('to', ValidationError::ORDER, 'the last night is before the first night');
            } elseif ($count >= self::MAX_NIGHTS) {
                $errors[] = new ValidationError('to', ValidationError::RANGE, 'an order covers at most a year');
            }
        }

        return [$from, $to, []];
    }

    /**
     * @param array<int|string, mixed> $typed
     * @param list<string>             $rooms
     * @param list<ValidationError>    $errors
     *
     * @return array<string, float>
     */
    private static function floors(array $typed, array $rooms, bool $required, array &$errors): array
    {
        $floors = [];
        foreach ($rooms as $room) {
            $text = trim((string) ($typed[$room] ?? ''));
            if ($text === '') {
                if ($required) {
                    $errors[] = new ValidationError('floors.' . $room, ValidationError::REQUIRED, 'give the minimum price');
                }
                continue;
            }
            if (preg_match('/^\d{1,7}([.,]\d{1,2})?$/', $text) !== 1 || (float) str_replace(',', '.', $text) <= 0) {
                $errors[] = new ValidationError('floors.' . $room, ValidationError::FORMAT, 'an amount above zero');
                continue;
            }
            $floors[$room] = (float) str_replace(',', '.', $text);
        }

        return $floors;
    }

    /**
     * @param list<ValidationError> $errors
     */
    private static function percentile(mixed $value, float $default, array &$errors): float
    {
        $text = trim((string) $value);
        if ($text === '') {
            return $default;
        }
        if (preg_match('/^\d{1,3}$/', $text) !== 1 || (int) $text > 110) {
            $errors[] = new ValidationError('percentile', ValidationError::RANGE, 'a market percentile from 0 to 110');

            return $default;
        }

        return (float) $text;
    }
}
