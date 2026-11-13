<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Write;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Write\Gate\FieldLocks;
use Ritmo\Application\Write\Gate\TonightGuard;
use Ritmo\Application\Write\Gate\TonightState;
use Ritmo\Application\Write\Gate\WriteRefused;
use Ritmo\Application\Write\Schedule\WriteStep;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Support\Days;

/**
 * Tonight never rises by a write (pending T6/T7 of M2, closed here): not by a floor above the
 * current price, not by a ceiling raised or released, not by deleting a rule that holds the price
 * down unless the rewrite of the group caps tonight at or below the current price.
 */
final class TonightGuardTest extends TestCase
{
    private const TODAY = '2027-03-03';

    /**
     * @param list<array<string, string>> $entries
     */
    private static function write(array $entries, string $group = ''): WriteStep
    {
        return new WriteStep(2, WriteOperation::OverridesWrite, 'Deluxe', ['pricing-1' => array_column($entries, 'date')], $entries, $group === '' ? 'D0' : 'rewrite', $group);
    }

    private static function delete(string $group = ''): WriteStep
    {
        return new WriteStep(1, WriteOperation::OverridesDelete, 'Deluxe', ['pricing-1' => [self::TODAY]], [self::TODAY], 'delete', $group);
    }

    /**
     * @param list<WriteStep> $steps
     */
    private static function reason(array $steps, TonightState $state): ?string
    {
        try {
            (new TonightGuard())->check($steps, self::TODAY, ['Deluxe' => $state], ['Deluxe' => 1000.0]);
        } catch (WriteRefused $refused) {
            return $refused->reason;
        }

        return null;
    }

    public function testAFloorAboveTonightsPriceIsRefusedUnlessItIsNotAboveTheFloorInForce(): void
    {
        $noRule = new TonightState('Deluxe', 900.0, null);
        self::assertSame(WriteRefused::RAISE_TODAY_FLOOR, self::reason([self::write([['date' => self::TODAY, 'min_price' => '-5']])], $noRule));
        self::assertNull(self::reason([self::write([['date' => self::TODAY, 'min_price' => '-10']])], $noRule), 'at the price is not a rise');
        $floor = new TonightState('Deluxe', 900.0, WriteSample::rule(self::TODAY, -5.0));
        self::assertNull(self::reason([self::write([['date' => self::TODAY, 'min_price' => '-5']])], $floor), 'the floor in force is kept');
    }

    public function testACeilingRaisedOrReleasedTonightIsRefused(): void
    {
        $capped = new TonightState('Deluxe', 1200.0, WriteSample::rule(self::TODAY, null, 20.0));
        self::assertSame(WriteRefused::RAISE_TODAY_CEILING, self::reason([self::write([['date' => self::TODAY, 'max_price' => '30']])], $capped));
        self::assertNull(self::reason([self::write([['date' => self::TODAY, 'max_price' => '10']])], $capped), 'a lower ceiling is fine');
        $fixedCap = new TonightState('Deluxe', 1100.0, new DateOverride(Days::date(self::TODAY), maxFixed: 1100.0));
        self::assertSame(WriteRefused::RAISE_TODAY_CEILING, self::reason([self::write([['date' => self::TODAY, 'max_price' => '20']])], $fixedCap));
        self::assertNull(self::reason([self::write([['date' => '2027-03-04', 'max_price' => '90']])], $capped), 'tomorrow is not tonight');
    }

    public function testDeletingTonightsRuleNeedsACappedRewrite(): void
    {
        $capped = new TonightState('Deluxe', 1100.0, WriteSample::rule(self::TODAY, null, 20.0));
        self::assertSame(WriteRefused::RAISE_TODAY_DELETION, self::reason([self::delete()], $capped));
        self::assertSame(WriteRefused::RAISE_TODAY_DELETION, self::reason([self::delete('g1'), self::write([['date' => self::TODAY, 'max_price' => '20']], 'g1')], $capped), 'a cap above the price lets it rise');
        self::assertNull(self::reason([self::delete('g1'), self::write([['date' => self::TODAY, 'max_price' => '10']], 'g1')], $capped));
        $fixedPrice = new TonightState('Deluxe', 610.0, WriteSample::rule(self::TODAY, null, null, 610.0));
        self::assertSame(WriteRefused::RAISE_TODAY_DELETION, self::reason([self::delete()], $fixedPrice), 'a price of its own holds tonight down too');
        $floorOnly = new TonightState('Deluxe', 1000.0, WriteSample::rule(self::TODAY, -10.0));
        self::assertNull(self::reason([self::delete()], $floorOnly), 'deleting a floor can only lower the price');
        self::assertNull(self::reason([self::delete()], new TonightState('Deluxe', 1000.0, null)));
    }

    public function testTonightWithoutAKnownPriceIsRefused(): void
    {
        self::assertSame(WriteRefused::TONIGHT_UNKNOWN, self::reason([self::write([['date' => self::TODAY, 'min_price' => '-50']])], new TonightState('Deluxe', null, null)));
    }

    public function testThePricingFieldLockRefusesPricesTypesAndExpiries(): void
    {
        foreach ([['price' => '700', 'price_type' => 'fixed'], ['min_price' => '610', 'min_price_type' => 'fixed'], ['lead_time_expiry' => '3']] as $fields) {
            try {
                (new FieldLocks())->check([self::write([['date' => '2027-03-10'] + $fields])], []);
                self::fail(implode(',', array_keys($fields)) . ' was allowed');
            } catch (WriteRefused $refused) {
                self::assertSame(WriteRefused::FORBIDDEN_FIELD, $refused->reason);
            }
        }
        (new FieldLocks())->check([self::write([['date' => '2027-03-10', 'min_price' => '-9', 'min_price_type' => 'percent_base']])], []);
        $this->addToAssertionCount(1);
    }
}
