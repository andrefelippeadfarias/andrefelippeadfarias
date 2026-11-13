<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid\Approvals;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * The warnings of an entry of a request (admin spec 5.4), as codes the screen turns into
 * sentences, and the "special" key that gives tonight, tomorrow and holiday nights their own
 * segment so their warnings stay visible. Pure.
 */
final class EntryWarnings
{
    public const TONIGHT = 'tonight';
    public const TOMORROW = 'tomorrow';
    public const HOLIDAY = 'holiday';
    public const GROUP = 'group';
    public const RELATION = 'relation';
    public const HELD = 'held';
    public const ORDER = 'order';

    public const ALL = [self::TONIGHT, self::TOMORROW, self::HOLIDAY, self::GROUP, self::RELATION, self::HELD, self::ORDER];

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function of(?DateTimeImmutable $night, DateTimeImmutable $today, string $holiday, bool $inGroup, bool $held, ?string $orderId, bool $linked): array
    {
        $warnings = [];
        $special = self::special($night, $today, $holiday);
        if ($special === self::TONIGHT || $special === self::TOMORROW) {
            $warnings[] = $special;
        }
        if ($holiday !== '') {
            $warnings[] = self::HOLIDAY;
        }
        foreach ([self::GROUP => $inGroup, self::RELATION => $linked, self::HELD => $held, self::ORDER => $orderId !== null] as $code => $applies) {
            if ($applies) {
                $warnings[] = $code;
            }
        }

        return $warnings;
    }

    /**
     * "tonight", "tomorrow", "holiday:<name>" or "" for an ordinary night (and for listing values).
     */
    public static function special(?DateTimeImmutable $night, DateTimeImmutable $today, string $holiday): string
    {
        if ($night === null) {
            return '';
        }
        $days = Days::diff($today, $night);
        if ($days <= 0) {
            return self::TONIGHT;
        }
        if ($days === 1) {
            return self::TOMORROW;
        }

        return $holiday === '' ? '' : self::HOLIDAY . ':' . $holiday;
    }
}
