<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Connectors\Beds24\CalendarWriteBody;
use Ritmo\Connectors\PriceLabs\ListingUpdateBody;
use Ritmo\Connectors\PriceLabs\OverrideWriteBody;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Tests\Support\Fixtures;

/**
 * The write bodies are built from whitelists: what each system may receive and nothing else.
 * The PMS body has parity with the reference's beds24.corpo_edicao (golden cases).
 */
final class WriteBodiesTest extends TestCase
{
    private const RULE = ['date' => '2027-03-10', 'reason' => 'pace step', 'min_price' => '-9', 'min_price_type' => 'percent_base', 'currency' => 'EUR'];

    private static function refused(callable $build): bool
    {
        try {
            $build();
        } catch (WriteBlocked) {
            return true;
        }

        return false;
    }

    public function testADateRuleBodyKeepsOnlyTheAllowedFields(): void
    {
        $body = OverrideWriteBody::write('pms', [self::RULE + ['max_price' => '20', 'max_price_type' => 'percent_base', 'min_stay' => '2', 'check_in_check_out_enabled' => '1', 'check_in' => '1111101', 'check_out' => '1111111']]);

        self::assertSame(['pms' => 'pms', 'update_children' => false], array_diff_key($body, ['overrides' => true]));
        self::assertSame(['2027-03-10'], OverrideWriteBody::dates($body));
        self::assertEqualsCanonicalizing(['reason', 'min_price', 'min_price_type', 'currency', 'max_price', 'max_price_type', 'min_stay', 'check_in_check_out_enabled', 'check_in', 'check_out'], OverrideWriteBody::fields($body));
        self::assertSame(['pms' => 'pms', 'update_children' => false, 'overrides' => [['date' => '2027-03-11'], ['date' => '2027-03-12']]], OverrideWriteBody::delete('pms', ['2027-03-11', '2027-03-12']));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function forbiddenRules(): array
    {
        return [
            'a price' => [['price' => '700', 'price_type' => 'fixed'] + self::RULE],
            'a fixed floor' => [['min_price_type' => 'fixed'] + self::RULE],
            'an expiry' => [['lead_time_expiry' => '3'] + self::RULE],
            'a percentage below -75' => [['min_price' => '-80'] + self::RULE],
            'a stay of 31' => [['min_stay' => '31'] + self::RULE],
            'one mask alone' => [['check_in' => '1111111'] + self::RULE],
            'masks without the switch' => [['check_in' => '1111111', 'check_out' => '1111111'] + self::RULE],
            'a floor above the ceiling' => [['max_price' => '-20', 'max_price_type' => 'percent_base'] + self::RULE],
            'only a reason' => [['date' => '2027-03-10', 'reason' => 'x']],
            'a bad date' => [['date' => '2027-02-30'] + self::RULE],
            'a reason on two lines' => [['reason' => "a\nb"] + self::RULE],
        ];
    }

    /**
     * @param array<string, mixed> $rule
     */
    #[DataProvider('forbiddenRules')]
    public function testADateRuleOutsideTheWhitelistNeverLeaves(array $rule): void
    {
        self::assertTrue(self::refused(static fn () => OverrideWriteBody::write('pms', [$rule])));
    }

    public function testACallNeverRepeatsADate(): void
    {
        self::assertTrue(self::refused(static fn () => OverrideWriteBody::write('pms', [self::RULE, self::RULE])));
        self::assertTrue(self::refused(static fn () => OverrideWriteBody::delete('pms', ['2027-03-10', '2027-03-10'])));
        self::assertTrue(self::refused(static fn () => OverrideWriteBody::write('pms', [])));
    }

    public function testAListingChangeIsOnlyMinBaseAndMaxInOrder(): void
    {
        self::assertSame(['listings' => [['id' => 'L1', 'pms' => 'pms', 'min' => 300.0, 'base' => 500.0]]], ListingUpdateBody::build('L1', 'pms', ['base' => 500.0, 'min' => 300.0]));
        self::assertTrue(self::refused(static fn () => ListingUpdateBody::build('L1', 'pms', ['min' => 600.0, 'base' => 500.0])));
        self::assertTrue(self::refused(static fn () => ListingUpdateBody::build('L1', 'pms', ['price' => 500.0])));
        self::assertTrue(self::refused(static fn () => ListingUpdateBody::build('L1', 'pms', ['base' => -1.0])));
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function corpoEdicao(): array
    {
        $cases = [];
        foreach (Fixtures::json('golden/m3/write/beds24.corpo_edicao.json')['cases'] as $case) {
            $cases[$case['name']] = [$case['input'], $case['expected']];
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[DataProvider('corpoEdicao')]
    public function testThePmsBodyIsTheReferences(array $input, array $expected): void
    {
        $range = ['roomId' => $input['room_id'], 'from' => $input['de'], 'to' => $input['ate']];
        foreach (['min_stay' => 'minStay', 'max_stay' => 'maxStay', 'override' => 'override', 'num_avail' => 'numAvail', 'canais_max' => 'channels'] as $from => $to) {
            if (array_key_exists($from, $input)) {
                $range[$to] = $input[$from];
            }
        }
        $units = [$input['room_id'] => $input['units']];

        if ($expected['refused'] ?? false) {
            self::assertTrue(self::refused(static fn () => CalendarWriteBody::build([$range], $units)));

            return;
        }
        $body = CalendarWriteBody::build([$range], $units);
        self::assertSame((int) $input['room_id'], $body[0]['roomId']);
        self::assertSame($expected['calendar'], $body[0]['calendar']);
    }

    public function testThePmsNeverReceivesAPriceABookingFieldOrTooManyRanges(): void
    {
        $range = ['roomId' => '902001', 'from' => '2027-03-12', 'to' => '2027-03-12'];
        self::assertTrue(self::refused(static fn () => CalendarWriteBody::build([$range + ['price1' => 500]], ['902001' => 4])));
        self::assertTrue(self::refused(static fn () => CalendarWriteBody::build([$range + ['Price' => 500]], ['902001' => 4])));
        self::assertTrue(self::refused(static fn () => CalendarWriteBody::build([$range + ['bookingId' => 7001]], ['902001' => 4])));
        self::assertTrue(self::refused(static fn () => CalendarWriteBody::build([$range + ['numAvail' => 1]], [])), 'units unknown: availability is not written');
        self::assertTrue(self::refused(static fn () => CalendarWriteBody::build(array_fill(0, 101, $range + ['minStay' => 2]), ['902001' => 4])));

        $body = CalendarWriteBody::build([$range + ['minStay' => 2], ['roomId' => '902002', 'from' => '2027-03-12', 'to' => '2027-03-13', 'channels' => ['booking' => null]], $range + ['maxStay' => 7]], ['902001' => 4]);
        self::assertSame([902001, 902002], array_column($body, 'roomId'), 'grouped by room in their order');
        self::assertSame(['902001' => ['2027-03-12', '2027-03-12'], '902002' => ['2027-03-12', '2027-03-13']], CalendarWriteBody::nights($body));
        self::assertSame(['minStay', 'maxStay', 'channels.booking'], CalendarWriteBody::fields($body));
    }
}
