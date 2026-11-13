<?php

declare(strict_types=1);

namespace Ritmo\Application\Approval;

use DateTimeImmutable;
use DateTimeZone;
use Ritmo\Domain\Support\Days;

/**
 * When a plan waiting for a person expires: at the property's next midnight (a plan of another
 * day is never written), or when the calendar it was built on gets older than the limit,
 * whichever comes first.
 *
 * Reference: validar_plano (plan of another day refused), AP 10/11 (a plan validated at dawn is
 * not written hours later without checking the sales again), AP 28/10 (the day of the property).
 */
final class ApprovalWindow
{
    private function __construct()
    {
    }

    /**
     * @param DateTimeImmutable $planDay the property's day the plan is for (date-only)
     *
     * @return DateTimeImmutable instant in UTC
     */
    public static function expiresAt(DateTimeImmutable $planDay, string $timezone, DateTimeImmutable $calendarReadAt, int $maxCalendarAgeHours): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $midnight = (new DateTimeImmutable(Days::key(Days::add($planDay, 1)) . ' 00:00:00', new DateTimeZone($timezone)))->setTimezone($utc);
        $stale = $calendarReadAt->setTimezone($utc)->modify(sprintf('+%d hours', $maxCalendarAgeHours));

        return min($midnight, $stale);
    }
}
