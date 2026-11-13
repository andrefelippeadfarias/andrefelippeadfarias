<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Stay\Write;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Stay\Write\Expiry\ExpiryRewrite;
use Ritmo\Domain\Stay\Write\Expiry\ExpiryWindow;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\ParityNames;

/**
 * pace.ajustar_tetos with expiring date rules (golden m4/stay) against ExpiryRewrite, applied to
 * the items as the ceiling release leaves them (a release on a rule without a ceiling is a plain
 * floor; on a rule with a ceiling it is already a group, its stay from the stay hook).
 * Divergence X1: the floor-only item on an expiring rule (2026-11-23) is plain in the reference
 * and a group here.
 */
final class ExpiryRewriteGoldenTest extends TestCase
{
    public function testExpiringNightsBecomeGroupsWithTheInheritedFields(): void
    {
        $case = Fixtures::json('golden/m4/stay/pace.ajustar_tetos.expiry.json')['cases'][0];
        $q = ParityNames::room('low');
        $rules = $expiries = [];
        foreach ($case['input']['rules'][$q] as $night => $r) {
            $rules[$q][$night] = new DateOverride(Days::date($night), (float) $r['min_price'], isset($r['max_price']) ? (float) $r['max_price'] : null, $r['min_stay'] ?? null, $r['check_in'] ?? null, $r['check_out'] ?? null);
            if (isset($r['lead_time_expiry'])) {
                $expiries[$q][$night] = ExpiryWindow::read(Days::date($night), $r['lead_time_expiry']);
            }
        }
        $expected = [];
        foreach ($case['expected']['items'] as $e) {
            $expected[$e['data']] = $e;
        }
        $released = [
            new PlanItem($q, Days::date('2026-11-20'), 1300.0, reasonCode: 'release'),
            new PlanItem($q, Days::date('2026-11-21'), 1300.0, minStay: $expected['2026-11-21']['min_stay'], reasonCode: 'release'),
            new PlanItem($q, Days::date('2026-11-22'), 1300.0, reasonCode: 'release'),
            new PlanItem($q, Days::date('2026-11-23'), 1250.0, reasonCode: 'floor only'),
            new PlanItem($q, Days::date('2026-11-24'), 1250.0, reasonCode: 'no rule'),
        ];
        $room = Fixtures::parityPreset()->roomType($q);
        [$items, $deletions] = ExpiryRewrite::apply($released, [new PlanDeletion($q, Days::date('2026-11-21'))], $rules, $expiries, [$q => $room]);

        $deleted = array_map(static fn (PlanDeletion $d): string => Days::key($d->date), $deletions);
        self::assertSame(['2026-11-21', '2026-11-20', '2026-11-23'], $deleted, 'the reference deletes 20 and 21; 23 is divergence X1');
        foreach ($items as $item) {
            $e = $expected[Days::key($item->date)];
            self::assertSame((float) $e['min_price'], $item->minPrice, $e['data']);
            if (Days::key($item->date) === '2026-11-23') {
                self::assertSame([null, null, null], [$item->minStay, $item->checkInMask, $item->checkOutMask], 'X1: the rule had nothing more to inherit');
                self::assertNull($item->maxPrice, 'the rule had no ceiling to inherit');
                self::assertSame(ExpiryRewrite::REASON, $item->reasonParams['rewrite']);
                continue;
            }
            self::assertSame($e['min_stay'] ?? null, $item->minStay, $e['data']);
            self::assertSame($e['check_in'] ?? null, $item->checkInMask, $e['data']);
            self::assertSame($e['check_out'] ?? null, $item->checkOutMask, $e['data']);
            self::assertNull($item->maxPrice, $e['data'] . ': a release keeps no ceiling');
        }
    }

    public function testTheWindowOfAnExpiringRule(): void
    {
        $window = ExpiryWindow::read(Days::date('2026-11-11'), 3);
        self::assertTrue($window->activeOn(Days::date('2026-11-07')));
        self::assertFalse($window->activeOn(Days::date('2026-11-08')), 'a rule of the 11th with 3 days stopped applying on the 8th');
        self::assertSame('2026-11-07', Days::key($window->lastActiveDay()));
        self::assertSame(2, ExpiryWindow::read(Days::date('2026-11-11'), '2')->days);
        self::assertNull(ExpiryWindow::read(Days::date('2026-11-11'), 2.5));
        self::assertNull(ExpiryWindow::read(Days::date('2026-11-11'), -1));
    }
}
