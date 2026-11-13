<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Write;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Application\Write\Gate\TonightState;
use Ritmo\Application\Write\Gate\WriteContext;
use Ritmo\Application\Write\Gate\WriteGate;
use Ritmo\Application\Write\WriteConfig;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\ListingChange;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Plan\ValidatedPlan;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Unit\Domain\EngineSample;
use Ritmo\Tests\Unit\Domain\Plan\PlanSample;

/**
 * The sample inn of EngineSample (Deluxe base 1000, listing pricing-1, PMS room pms-1, 4 units;
 * Standard base 500, pricing-2, pms-2, 6 units) for the write path: "now" is Wednesday 3 March
 * 2027 at noon UTC, so tonight is the 3rd. Tonight's price is 1000 (Deluxe) and 500 (Standard)
 * unless a test says otherwise.
 */
final class WriteSample
{
    public const NOW = '2027-03-03T12:00:00Z';
    public const TODAY = '2027-03-03';

    public static function clock(): MovableClock
    {
        return new MovableClock(self::NOW);
    }

    public static function property(PropertyMode $mode = PropertyMode::Auto): Property
    {
        return new Property('inn', 'Sample Inn', EngineSample::TIMEZONE, 'EUR', $mode);
    }

    /**
     * @return list<RoomType>
     */
    public static function rooms(): array
    {
        return [EngineSample::deluxe(), EngineSample::standard()];
    }

    /**
     * A plan sealed by the Validator, of the clock's today.
     *
     * @param list<PlanItem>      $items
     * @param list<PlanDeletion>  $deletions
     * @param list<ListingChange> $listing
     */
    public static function validated(Clock $clock, array $items, array $deletions = [], array $listing = []): ValidatedPlan
    {
        $plan = new Plan('inn', $clock->today(EngineSample::TIMEZONE), $clock->now(), $items, $deletions, $listing);
        $orders = PlanSample::orders([['id' => 'listing', 'type' => 'rule', 'releases' => ['listing']]]);
        $result = (new Validator(EngineSample::rules(), $clock))->validate($plan, self::property(), self::rooms(), PlanSample::context(orders: $orders));
        Assert::assertTrue($result->isValid(), implode(', ', $result->errorCodes()));
        Assert::assertNotNull($result->plan);

        return $result->plan;
    }

    /**
     * @param array<string, mixed> $with PlanItem arguments
     */
    public static function item(array $with = []): PlanItem
    {
        return PlanSample::item($with);
    }

    /**
     * @param array<string, DateOverride|null> $rules room code => tonight's stored rule
     *
     * @return array<string, TonightState>
     */
    public static function tonight(float $deluxe = 1000.0, float $standard = 500.0, array $rules = []): array
    {
        return [
            'Deluxe' => new TonightState('Deluxe', $deluxe, $rules['Deluxe'] ?? null),
            'Standard' => new TonightState('Standard', $standard, $rules['Standard'] ?? null),
        ];
    }

    /**
     * @param array<string, TonightState>|null $tonight
     * @param Closure(int): bool|null          $canApprove
     */
    public static function context(Clock $clock, PropertyMode $mode = PropertyMode::Auto, ?array $tonight = null, bool $lockHeld = true, ?Closure $canApprove = null, ?DateTimeImmutable $readAt = null): WriteContext
    {
        return new WriteContext(
            self::property($mode),
            self::rooms(),
            $readAt ?? $clock->now(),
            $tonight ?? self::tonight(),
            static fn (): bool => $lockHeld,
            'sample-pms',
            true,
            $canApprove,
        );
    }

    public static function gate(Clock $clock, ?EmergencySwitch $switch = null, ?WriteConfig $config = null): WriteGate
    {
        return new WriteGate($clock, $switch ?? self::switch(), $config ?? new WriteConfig());
    }

    public static function switch(): EmergencySwitch
    {
        return new EmergencySwitch(new ArrayStore());
    }

    public static function rule(string $date, ?float $min = null, ?float $max = null, ?float $fixedPrice = null): DateOverride
    {
        return new DateOverride(Days::date($date), $min, $max, null, null, null, '', $fixedPrice, $fixedPrice === null ? null : DateOverride::PRICE_FIXED);
    }
}
