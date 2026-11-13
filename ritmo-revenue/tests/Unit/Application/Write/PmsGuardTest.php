<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Write;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Write\Execute\PmsGuard;
use Ritmo\Application\Write\Journal\StoredState;
use Ritmo\Application\Write\Schedule\CalendarChange;
use Ritmo\Application\Write\Schedule\WriteStep;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;

/**
 * The last check right before a PMS block: every stay value read before the plan (minimum and
 * maximum stay, restriction) must still be there, or the change is skipped.
 */
final class PmsGuardTest extends TestCase
{
    private const NIGHT = '2027-03-13';

    public function testAMinimumStayChangedSinceTheReadIsSkipped(): void
    {
        self::assertSame([PmsGuard::CHANGED], self::skipped(self::change(minStay: 2, minStayBefore: 1), ['minStay' => 3, 'override' => 'none']));
    }

    public function testARestrictionChangedSinceTheReadIsSkipped(): void
    {
        $change = self::change(restriction: Restriction::NoCheckinCheckout, read: ['override' => 'none']);

        self::assertSame([PmsGuard::CHANGED], self::skipped($change, ['override' => 'noCheckOut', 'minStay' => 1]));
        self::assertSame([], self::skipped($change, ['override' => 'none', 'minStay' => 1]), 'unchanged: sent');
        self::assertSame([PmsGuard::AT_TARGET], self::skipped($change, ['override' => 'noCheckInOrCheckOut', 'minStay' => 1]));
    }

    public function testAMaximumStayChangedSinceTheReadIsSkipped(): void
    {
        $change = self::change(maxStay: 3, read: ['maxStay' => 7]);

        self::assertSame([PmsGuard::CHANGED], self::skipped($change, ['maxStay' => 10]));
        self::assertSame([], self::skipped($change, ['maxStay' => 7]));
    }

    public function testAMinimumStayOfNoneIsAMinimumOfOne(): void
    {
        self::assertSame([], self::skipped(self::change(minStay: 2, minStayBefore: 1), ['override' => 'none']), 'no minimum now = 1');
        self::assertSame([PmsGuard::CHANGED], self::skipped(self::change(minStay: 2, minStayBefore: 1), ['minStay' => 5]));
    }

    public function testAChangeWithoutAReadIsNotGuarded(): void
    {
        self::assertSame([], self::skipped(self::change(minStay: 2), ['minStay' => 5]));
    }

    /**
     * @param array<string, int|string|null> $read
     */
    private static function change(?int $minStay = null, ?int $maxStay = null, ?Restriction $restriction = null, ?int $minStayBefore = null, array $read = []): CalendarChange
    {
        $night = Days::date(self::NIGHT);

        return new CalendarChange('STD', '902001', $night, $night, minStay: $minStay, maxStay: $maxStay, restriction: $restriction, minStayBefore: $minStayBefore, read: $read);
    }

    /**
     * @param array<string, mixed> $now the PMS night right before the write
     *
     * @return list<string> the reasons of the changes skipped
     */
    private static function skipped(CalendarChange $change, array $now): array
    {
        $step = new WriteStep(1, WriteOperation::PmsCalendar, 'STD', ['902001' => $change->nights()], [$change->range()], 'pms 1/1', '', [$change]);
        [, $skipped] = (new PmsGuard())->filter($step, [], new StoredState([], ['902001' => [self::NIGHT => $now]]));

        return array_column($skipped, 'reason');
    }
}
