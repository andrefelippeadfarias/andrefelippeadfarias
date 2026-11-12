<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use DateTimeImmutable;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\ListingInfo;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Support\Days;

/**
 * Turns what a stage hands to the next one into plain arrays (stored as JSON with the run) and
 * back. Only facts of rooms and nights travel this way; nothing about guests.
 */
final class StateCodec
{
    /**
     * @return array{ref: string, min: float|null, base: float|null, max: float|null, currency: string|null, push_enabled: bool, last_pushed_at: string|null, last_refreshed_at: string|null}
     */
    public static function listing(ListingInfo $listing): array
    {
        return [
            'ref' => $listing->ref,
            'min' => $listing->min,
            'base' => $listing->base,
            'max' => $listing->max,
            'currency' => $listing->currency,
            'push_enabled' => $listing->sync->pushEnabled,
            'last_pushed_at' => $listing->sync->lastPushedAt?->format(DATE_ATOM),
            'last_refreshed_at' => $listing->sync->lastRefreshedAt?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function override(DateOverride $override): array
    {
        return [
            'date' => Days::key($override->date),
            'min_percent' => $override->minPercent,
            'max_percent' => $override->maxPercent,
            'min_stay' => $override->minStay,
            'checkin_mask' => $override->checkinMask,
            'checkout_mask' => $override->checkoutMask,
            'reason' => $override->reason,
            'price' => $override->price,
            'price_type' => $override->priceType,
            'min_fixed' => $override->minFixed,
            'max_fixed' => $override->maxFixed,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function toOverride(array $data): DateOverride
    {
        return new DateOverride(
            Days::date((string) $data['date']),
            self::float($data['min_percent'] ?? null),
            self::float($data['max_percent'] ?? null),
            self::int($data['min_stay'] ?? null),
            self::text($data['checkin_mask'] ?? null),
            self::text($data['checkout_mask'] ?? null),
            (string) ($data['reason'] ?? ''),
            self::float($data['price'] ?? null),
            self::text($data['price_type'] ?? null),
            self::float($data['min_fixed'] ?? null),
            self::float($data['max_fixed'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function priceNight(PriceNight $night): array
    {
        return [
            'date' => Days::key($night->date),
            'price' => $night->price,
            'min_price' => $night->minPrice,
            'max_price' => $night->maxPrice,
            'min_stay' => $night->minStay,
            'sold' => $night->sold,
            'total' => $night->total,
            'has_override' => $night->hasDateOverride,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function toPriceNight(array $data): PriceNight
    {
        return new PriceNight(
            Days::date((string) $data['date']),
            (float) $data['price'],
            self::float($data['min_price'] ?? null),
            self::float($data['max_price'] ?? null),
            self::int($data['min_stay'] ?? null),
            (int) $data['sold'],
            (int) $data['total'],
            (bool) ($data['has_override'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function planItem(PlanItem $item): array
    {
        return [
            'room_code' => $item->roomCode,
            'date' => Days::key($item->date),
            'min_price' => $item->minPrice,
            'max_price' => $item->maxPrice,
            'min_stay' => $item->minStay,
            'reason_code' => $item->reasonCode,
            'reason_params' => $item->reasonParams,
            'before' => $item->before?->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function toPlanItem(array $data): PlanItem
    {
        $before = is_array($data['before'] ?? null) ? $data['before'] : null;

        return new PlanItem(
            (string) $data['room_code'],
            Days::date((string) $data['date']),
            self::float($data['min_price'] ?? null),
            self::float($data['max_price'] ?? null),
            self::int($data['min_stay'] ?? null),
            (string) ($data['reason_code'] ?? ''),
            is_array($data['reason_params'] ?? null) ? $data['reason_params'] : [],
            $before === null ? null : new NightState(
                self::float($before['price'] ?? null),
                self::float($before['min_price'] ?? null),
                self::float($before['max_price'] ?? null),
                self::int($before['min_stay'] ?? null),
                self::float($before['min_percent'] ?? null),
                self::float($before['max_percent'] ?? null),
            ),
        );
    }

    public static function instant(?string $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : new DateTimeImmutable($value);
    }

    private static function float(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private static function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
