<?php

declare(strict_types=1);

namespace Ritmo\Admin\Licence;

use DateTimeImmutable;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Application\Licence\LicenceRefused;
use Ritmo\Application\Update\UpdateRefused;
use Ritmo\Domain\Licence\LicenceReason;
use Ritmo\Domain\Licence\LicenceRecord;
use Ritmo\Domain\Licence\LicenceStanding;
use Ritmo\Domain\Licence\LicenceVerdict;

/**
 * The words of the licence and update codes, translated for the reader. Dates in the site's
 * timezone and formats.
 */
final class LicenceText
{
    private function __construct()
    {
    }

    public static function standing(LicenceStanding $standing): string
    {
        return match ($standing) {
            LicenceStanding::Active => __('Active', 'ritmo-revenue'),
            LicenceStanding::Grace => __('Grace period', 'ritmo-revenue'),
            LicenceStanding::Lapsed => __('Lapsed', 'ritmo-revenue'),
            LicenceStanding::Unlicensed => __('No licence', 'ritmo-revenue'),
        };
    }

    /**
     * Why the licence stands where it stands, in one sentence.
     */
    public static function reason(LicenceVerdict $verdict): string
    {
        return match ($verdict->reason) {
            LicenceReason::NONE => __('The licence is valid.', 'ritmo-revenue'),
            LicenceReason::NO_KEY => __('No licence key is saved.', 'ritmo-revenue'),
            LicenceReason::NOT_CHECKED => __('The licence key is saved but has not been confirmed yet.', 'ritmo-revenue'),
            LicenceReason::TEST_PROVIDER => __('This is a test key, which only works in development mode.', 'ritmo-revenue'),
            LicenceReason::UNREACHABLE => __('The licence server could not be reached; the last valid answer is kept.', 'ritmo-revenue'),
            LicenceReason::EXPIRED => __('The licence has expired.', 'ritmo-revenue'),
            default => __('The licence server says this key is not valid for this site.', 'ritmo-revenue'),
        };
    }

    /**
     * What the standing means for the properties and the assistant.
     */
    public static function consequence(LicenceVerdict $verdict, DateTimeImmutable $now, string $timezone): string
    {
        $ends = $verdict->graceEndsAt;

        return match ($verdict->standing) {
            LicenceStanding::Active => __('Every mode is available and the AI assistant can be used.', 'ritmo-revenue'),
            LicenceStanding::Grace => sprintf(
                /* translators: 1: date the grace period ends, 2: whole days left */
                _n(
                    'Everything keeps working until %1$s (%2$d day left). After that, every property goes back to shadow mode at the start of its next run and the AI assistant switches off.',
                    'Everything keeps working until %1$s (%2$d days left). After that, every property goes back to shadow mode at the start of its next run and the AI assistant switches off.',
                    (int) $verdict->graceDaysLeft($now),
                    'ritmo-revenue'
                ),
                $ends === null ? '' : NightDate::date($ends, $timezone),
                (int) $verdict->graceDaysLeft($now)
            ),
            LicenceStanding::Lapsed => sprintf(
                /* translators: %s: date the grace period ended */
                __('The grace period ended on %s. Every property runs in shadow mode: the plugin shows what it would do and writes nothing. The AI assistant is off.', 'ritmo-revenue'),
                $ends === null ? '' : NightDate::date($ends, $timezone)
            ),
            LicenceStanding::Unlicensed => __('The plugin works in shadow mode only: it shows what it would do and writes nothing. Approval and autonomous modes and the AI assistant need a valid licence.', 'ritmo-revenue'),
        };
    }

    /**
     * The outcome of a check as the provider gave it.
     */
    public static function answer(string $status): string
    {
        return match ($status) {
            LicenceRecord::VALID => __('valid', 'ritmo-revenue'),
            LicenceRecord::EXPIRED => __('expired', 'ritmo-revenue'),
            LicenceRecord::INVALID => __('not valid', 'ritmo-revenue'),
            LicenceRecord::UNREACHABLE => __('server not reached', 'ritmo-revenue'),
            default => __('not checked yet', 'ritmo-revenue'),
        };
    }

    public static function refusal(LicenceRefused $refused): string
    {
        return match ($refused->reason) {
            LicenceRefused::BAD_KEY => __('A licence key has 8 to 128 letters, digits, dots, colons, hyphens or underscores, without spaces.', 'ritmo-revenue'),
            LicenceRefused::COOLDOWN => __('The licence was checked moments ago. Try again in half a minute.', 'ritmo-revenue'),
            default => __('The key cannot be saved: RITMO_SECRET_KEY in wp-config.php needs fixing first.', 'ritmo-revenue'),
        };
    }

    /**
     * Why an update was not offered or not installed.
     */
    public static function updateRefusal(string $reason): string
    {
        return match ($reason) {
            UpdateRefused::NOT_CONFIGURED => __('The update channel is not configured.', 'ritmo-revenue'),
            UpdateRefused::UNREACHABLE => __('The update server could not be reached. Nothing was changed.', 'ritmo-revenue'),
            UpdateRefused::UNSIGNED, UpdateRefused::BAD_SIGNATURE, UpdateRefused::MALFORMED => __('The update information is not signed by the publisher of Ritmo Revenue. Nothing was installed.', 'ritmo-revenue'),
            UpdateRefused::WRONG_SLUG, UpdateRefused::OTHER_ORIGIN, UpdateRefused::UNKNOWN_PACKAGE => __('The update package does not come from the update server of Ritmo Revenue. Nothing was installed.', 'ritmo-revenue'),
            UpdateRefused::NOT_NEWER => __('The update is not newer than the installed version. Nothing was installed.', 'ritmo-revenue'),
            default => __('The downloaded update does not match its signature. Nothing was installed.', 'ritmo-revenue'),
        };
    }
}
