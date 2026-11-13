<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

use DateTimeImmutable;
use Ritmo\Domain\Calendar\Stay\PricingStayNight;
use Ritmo\Domain\Calendar\Stay\PricingSync;
use Ritmo\Domain\Calendar\Stay\StaySyncCapability;
use Ritmo\Domain\Calendar\Stay\StaySyncField;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;

/**
 * What the pricing system's push sets on one PMS room night (spec 06, D6): the effective minimum
 * stay of its calendar (rooms whose minimum stay it pushes) and the arrival/departure restriction
 * of the night's weekday in the masks in force, the date rule's, else the listing's (rooms whose
 * arrival and departure it pushes). A field it does not push, or cannot know, is left out.
 *
 * Shared by the third-party comparison (a PMS value equal to this is the push, not a person) and
 * by the pairing of the stay writes (the pricing system already holds the value).
 */
final class PushedPmsValues
{
    public function __construct(
        private readonly PricingSync $sync,
        private readonly StaySyncCapability $capability,
    ) {
    }

    /**
     * @param PricingStayNight|null $night the night of the pricing calendar read in this run (its
     *                                     effective stay and the listing's masks)
     * @param DateOverride|null     $rule  the date rule in force that night
     */
    public function of(string $room, DateTimeImmutable $date, ?PricingStayNight $night, ?DateOverride $rule): NightFields
    {
        $fields = [];
        if ($night !== null && $this->sync->pushes(StaySyncField::MinStay, $room, $this->capability)) {
            $fields[ChangeField::PMS_MIN_STAY] = $night->minStay;
        }
        if ($this->sync->pushes(StaySyncField::ArrivalDeparture, $room, $this->capability)) {
            $fields[ChangeField::PMS_RESTRICTION] = self::restriction($date, $night, $rule)?->value;
        }

        return new NightFields($fields);
    }

    /**
     * Every night of a room's pricing calendar.
     *
     * @param array<string, PricingStayNight>  $calendar  'YYYY-MM-DD' => night
     * @param array<string, DateOverride>|null $dateRules 'YYYY-MM-DD' => rule in force
     *
     * @return array<string, NightFields> 'YYYY-MM-DD' => what the push sets
     */
    public function calendar(string $room, array $calendar, ?array $dateRules): array
    {
        $out = [];
        foreach ($calendar as $key => $night) {
            $out[(string) $key] = $this->of($room, $night->date, $night, $dateRules[$key] ?? null);
        }

        return $out;
    }

    /**
     * The restriction the masks in force give the night's weekday: arrival and departure closed,
     * one of them, or none; null when no mask is known.
     */
    public static function restriction(DateTimeImmutable $date, ?PricingStayNight $night, ?DateOverride $rule): ?Restriction
    {
        $listing = $night !== null && $night->masksEnabled;
        $in = $rule?->checkinMask ?? ($listing ? $night->checkinMask : null);
        $out = $rule?->checkoutMask ?? ($listing ? $night->checkoutMask : null);
        if ($in === null && $out === null) {
            return null;
        }
        $weekday = Days::weekday($date);
        $arrivalClosed = $in !== null && $in[$weekday] === '0';
        $departureClosed = $out !== null && $out[$weekday] === '0';

        return match (true) {
            $arrivalClosed && $departureClosed => Restriction::NoCheckinCheckout,
            $arrivalClosed => Restriction::NoCheckin,
            $departureClosed => Restriction::NoCheckout,
            default => Restriction::None,
        };
    }
}
