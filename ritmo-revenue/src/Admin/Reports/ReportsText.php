<?php

declare(strict_types=1);

namespace Ritmo\Admin\Reports;

use DateTimeImmutable;
use Ritmo\Domain\Report\Booking\BookingLine;
use Ritmo\Domain\Report\Occupancy\RowKind;
use Ritmo\Domain\Report\Review\Reading;
use Ritmo\Domain\Report\Targets\Scenario;
use Ritmo\Domain\Report\Weekly\Moment;

/**
 * The words of the reports: channels, readings, moments, scenarios, row kinds and months.
 */
final class ReportsText
{
    private function __construct()
    {
    }

    public static function channel(string $channel): string
    {
        return match ($channel) {
            'booking' => __('Booking.com', 'ritmo-revenue'),
            'airbnb' => __('Airbnb', 'ritmo-revenue'),
            'expedia' => __('Expedia', 'ritmo-revenue'),
            BookingLine::DIRECT_API => __('Direct (website or booking engine)', 'ritmo-revenue'),
            BookingLine::DIRECT_MANUAL => __('Direct (typed into the PMS)', 'ritmo-revenue'),
            '' => __('Other', 'ritmo-revenue'),
            default => ucfirst($channel),
        };
    }

    public static function reading(?Reading $reading): string
    {
        return match ($reading) {
            Reading::Early => __('too early to compare', 'ritmo-revenue'),
            Reading::Ahead => __('ahead', 'ritmo-revenue'),
            Reading::OnPace => __('on pace', 'ritmo-revenue'),
            Reading::Behind => __('behind', 'ritmo-revenue'),
            null => '',
        };
    }

    public static function moment(Moment $moment): string
    {
        return match ($moment) {
            Moment::Past => __('past', 'ritmo-revenue'),
            Moment::Current => __('this week', 'ritmo-revenue'),
            Moment::Future => __('ahead', 'ritmo-revenue'),
        };
    }

    public static function scenario(Scenario $scenario): string
    {
        return match ($scenario) {
            Scenario::Floor => __('Floor', 'ritmo-revenue'),
            Scenario::Base => __('Base', 'ritmo-revenue'),
            Scenario::Goal => __('Goal', 'ritmo-revenue'),
        };
    }

    public static function rowKind(RowKind $kind): string
    {
        return match ($kind) {
            RowKind::Room => __('Room', 'ritmo-revenue'),
            RowKind::Site => __('All properties', 'ritmo-revenue'),
            RowKind::Group => __('Room group', 'ritmo-revenue'),
            RowKind::Property => __('Property', 'ritmo-revenue'),
        };
    }

    /**
     * "November 2026" in the site's language.
     */
    public static function month(string $month): string
    {
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');

        return $first === false ? $month : wp_date('F Y', $first->getTimestamp(), new \DateTimeZone('UTC'));
    }

    /**
     * Monday = 0 ... Sunday = 6, the short name in the site's language.
     */
    public static function weekday(int $weekday): string
    {
        global $wp_locale;
        $sunday = ($weekday + 1) % 7;

        return is_object($wp_locale) ? $wp_locale->get_weekday_abbrev($wp_locale->get_weekday($sunday)) : (string) $weekday;
    }
}
