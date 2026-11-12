<?php

declare(strict_types=1);

namespace Ritmo\Domain\Orders;

use DateTimeImmutable;
use Exception;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Support\Days;

/**
 * Builds owner orders from stored data (the rows of the orders table or an imported file),
 * reporting every problem of a row at once.
 *
 * Keys: id, type, rooms, from, to, nights, excluded_nights (a list for every room or a map
 * room => list), given_on, created_at (ISO 8601 instant; without an offset it is UTC), releases,
 * floors, percentile, base_sold, note, and the target terms sales_ramp, free_step, only_down,
 * strong_night_occupancy, strong_night_ahead_occupancy, top, fallback_floors,
 * fallback_trigger_days, fallback_min_days, descent_floors, descent_marks.
 */
final class OrderReader
{
    private const KEYS = [
        'id', 'type', 'rooms', 'from', 'to', 'nights', 'excluded_nights', 'given_on', 'created_at', 'releases', 'floors',
        'percentile', 'base_sold', 'note', 'sales_ramp', 'free_step', 'only_down', 'strong_night_occupancy',
        'strong_night_ahead_occupancy', 'top', 'fallback_floors', 'fallback_trigger_days', 'fallback_min_days',
        'descent_floors', 'descent_marks',
    ];

    /** @var list<ValidationError> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $row
     *
     * @throws InvalidConfig with every problem of the row
     */
    public function read(array $row): OwnerOrder
    {
        $this->errors = [];
        foreach (array_diff(array_keys($row), self::KEYS) as $unknown) {
            $this->error((string) $unknown, ValidationError::UNKNOWN, 'is not a known order field');
        }
        $id = is_string($row['id'] ?? null) ? $row['id'] : '';
        if (preg_match(OwnerOrder::ID_PATTERN, $id) !== 1) {
            $this->error('id', ValidationError::FORMAT, 'must be 1 to 64 letters, digits, dots, dashes or underscores');
        }
        $type = OrderType::tryFrom(is_string($row['type'] ?? null) ? $row['type'] : '');
        if ($type === null) {
            $this->error('type', ValidationError::CHOICE, 'must be one of: ' . implode(', ', array_column(OrderType::cases(), 'value')));
        }
        $from = $this->date($row, 'from', true);
        $to = $this->date($row, 'to', true);
        if ($from !== null && $to !== null && $to < $from) {
            $this->error('to', ValidationError::ORDER, 'must not be before the first night');
        }
        $order = [
            'rooms' => $this->rooms($row['rooms'] ?? null),
            'nights' => $this->dates($row['nights'] ?? [], 'nights'),
            'excluded' => $this->excluded($row['excluded_nights'] ?? []),
            'given_on' => $this->date($row, 'given_on', false),
            'created_at' => $this->instant($row['created_at'] ?? null),
            'releases' => $this->releases($row['releases'] ?? []),
            'floors' => $this->amounts($row['floors'] ?? [], 'floors'),
            'percentile' => $this->number($row['percentile'] ?? null, 'percentile', 0, 110),
            'base_sold' => $this->baseSold($row['base_sold'] ?? []),
            'terms' => $this->terms($row),
        ];
        $note = $row['note'] ?? '';
        if (!is_string($note)) {
            $this->error('note', ValidationError::TYPE, 'must be text');
        }
        if ($this->errors !== [] || $type === null || $from === null || $to === null) {
            throw new InvalidConfig(sprintf('owner order "%s"', $id), $this->errors);
        }

        return new OwnerOrder(
            $id,
            $type,
            $order['rooms'],
            $from,
            $to,
            $order['nights'],
            $order['excluded'][0],
            $order['excluded'][1],
            $order['given_on'],
            $order['created_at'],
            $order['releases'],
            $order['floors'],
            $order['percentile'],
            $order['base_sold'],
            $order['terms'],
            is_string($note) ? $note : '',
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function terms(array $row): TargetTerms
    {
        $flags = [];
        foreach (['sales_ramp', 'free_step', 'only_down'] as $key) {
            $flags[$key] = $row[$key] ?? false;
            if (!is_bool($flags[$key])) {
                $this->error($key, ValidationError::TYPE, 'must be true or false');
                $flags[$key] = false;
            }
        }
        $descent = [];
        $floors = $row['descent_floors'] ?? [];
        if (!is_array($floors)) {
            $this->error('descent_floors', ValidationError::TYPE, 'must be a map of room codes to lists of amounts');
            $floors = [];
        }
        foreach ($floors as $room => $amounts) {
            $descent[(string) $room] = array_values($this->amounts(is_array($amounts) && array_is_list($amounts) ? $amounts : [], "descent_floors.$room"));
        }
        $marks = $row['descent_marks'] ?? null;
        if ($marks !== null && (!is_array($marks) || !array_is_list($marks) || array_filter($marks, static fn (mixed $m): bool => !is_int($m) || $m < 0) !== [])) {
            $this->error('descent_marks', ValidationError::TYPE, 'must be a list of whole numbers of days');
            $marks = null;
        }

        return new TargetTerms(
            $flags['sales_ramp'],
            $flags['free_step'],
            $flags['only_down'],
            $this->number($row['strong_night_occupancy'] ?? null, 'strong_night_occupancy', 0, 1),
            $this->number($row['strong_night_ahead_occupancy'] ?? null, 'strong_night_ahead_occupancy', 0, 1),
            $this->amounts($row['top'] ?? [], 'top'),
            $this->amounts($row['fallback_floors'] ?? [], 'fallback_floors'),
            $this->days($row['fallback_trigger_days'] ?? null, 'fallback_trigger_days'),
            $this->days($row['fallback_min_days'] ?? null, 'fallback_min_days'),
            $descent,
            $marks,
        );
    }

    /**
     * @return list<string>
     */
    private function rooms(mixed $rooms): array
    {
        if (!is_array($rooms) || $rooms === [] || !array_is_list($rooms)) {
            $this->error('rooms', ValidationError::REQUIRED, 'must list at least one room code, or "*"');

            return [];
        }
        foreach ($rooms as $i => $room) {
            if (!is_string($room) || ($room !== OwnerOrder::ALL_ROOMS && !Identifier::isCode($room))) {
                $this->error("rooms.$i", ValidationError::FORMAT, 'must be a room code or "*"');
            }
        }

        return array_values(array_filter($rooms, 'is_string'));
    }

    /**
     * @return array{list<string>, array<string, list<string>>}
     */
    private function excluded(mixed $value): array
    {
        if (is_array($value) && array_is_list($value)) {
            return [$this->dates($value, 'excluded_nights'), []];
        }
        if (!is_array($value)) {
            $this->error('excluded_nights', ValidationError::TYPE, 'must be a list of nights or a map of room codes to nights');

            return [[], []];
        }
        $byRoom = [];
        foreach ($value as $room => $nights) {
            $byRoom[(string) $room] = $this->dates($nights, "excluded_nights.$room");
        }

        return [[], $byRoom];
    }

    /**
     * @return list<string>
     */
    private function dates(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->error($path, ValidationError::TYPE, 'must be a list of dates');

            return [];
        }
        foreach ($value as $i => $night) {
            if (!is_string($night) || !Days::isValid($night)) {
                $this->error("$path.$i", ValidationError::FORMAT, 'must be a date as YYYY-MM-DD');
            }
        }

        return array_values(array_filter($value, static fn (mixed $n): bool => is_string($n) && Days::isValid($n)));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function date(array $row, string $key, bool $required): ?DateTimeImmutable
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            if ($required) {
                $this->error($key, ValidationError::REQUIRED, 'is required');
            }

            return null;
        }
        if (!is_string($value) || !Days::isValid($value)) {
            $this->error($key, ValidationError::FORMAT, 'must be a date as YYYY-MM-DD');

            return null;
        }

        return Days::date($value);
    }

    private function instant(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})?$/', $value) === 1) {
            try {
                return new DateTimeImmutable($value, new \DateTimeZone('UTC'));
            } catch (Exception) {
                // reported below
            }
        }
        $this->error('created_at', ValidationError::FORMAT, 'must be an ISO 8601 instant');

        return null;
    }

    /**
     * @return list<OrderRelease>
     */
    private function releases(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->error('releases', ValidationError::TYPE, 'must be a list');

            return [];
        }
        $releases = [];
        foreach ($value as $i => $name) {
            $release = OrderRelease::tryFrom(is_string($name) ? $name : '');
            if ($release === null) {
                $this->error("releases.$i", ValidationError::CHOICE, 'must be one of: ' . implode(', ', array_column(OrderRelease::cases(), 'value')));
                continue;
            }
            $releases[] = $release;
        }

        return $releases;
    }

    /**
     * @return array<int|string, float>
     */
    private function amounts(mixed $value, string $path): array
    {
        if (!is_array($value)) {
            $this->error($path, ValidationError::TYPE, 'must be a map of amounts');

            return [];
        }
        $amounts = [];
        foreach ($value as $key => $amount) {
            if ((!is_int($amount) && !is_float($amount)) || $amount <= 0) {
                $this->error("$path.$key", ValidationError::RANGE, 'must be an amount greater than zero');
                continue;
            }
            $amounts[$key] = (float) $amount;
        }

        return $amounts;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function baseSold(mixed $value): array
    {
        if (!is_array($value)) {
            $this->error('base_sold', ValidationError::TYPE, 'must be a map of room codes to nights');

            return [];
        }
        $out = [];
        foreach ($value as $room => $nights) {
            foreach (is_array($nights) ? $nights : [] as $night => $sold) {
                if (!Days::isValid((string) $night) || !is_int($sold) || $sold < 0) {
                    $this->error("base_sold.$room.$night", ValidationError::FORMAT, 'must map a night to the units sold');
                    continue;
                }
                $out[(string) $room][(string) $night] = $sold;
            }
        }

        return $out;
    }

    private function number(mixed $value, string $path, float $min, float $max): ?float
    {
        if ($value === null) {
            return null;
        }
        if ((!is_int($value) && !is_float($value)) || $value < $min || $value > $max) {
            $this->error($path, ValidationError::RANGE, sprintf('must be a number from %s to %s', $min, $max));

            return null;
        }

        return (float) $value;
    }

    private function days(mixed $value, string $path): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 0 || $value > 365) {
            $this->error($path, ValidationError::RANGE, 'must be a whole number of days from 0 to 365');

            return null;
        }

        return $value;
    }

    private function error(string $path, string $code, string $message): void
    {
        $this->errors[] = new ValidationError($path, $code, $message);
    }
}
