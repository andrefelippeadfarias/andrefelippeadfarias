<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Support\Days;

/**
 * The stay plan of one room for the pricing system: per night, the stay the rules ask against the
 * stay in force there (the effective stay when known, else the calendar's), and the date-override
 * items that correct it (no prices: floors are the pricing engine's).
 *
 * Where the arrival rule decides, the stay goes up to its target and down only to one night
 * (target one, or the orphan night); with the date rules read, a target of two or more nights is
 * written explicitly on the date rule. Today's free closed day after a past night goes to one
 * night. Closed days, holidays and packages below their own stay are only reported in the
 * stay-only plan (the full plan raises them). On an enforced open day, a date rule (or the
 * listing) that closes that weekday gets masks with the weekday open.
 *
 * Reference: calendario_12m.comparar (stay part) and montar_plano.
 */
final class PricingStayPlan
{
    public function __construct(private readonly StayRules $rules)
    {
    }

    /**
     * @param array<string, PricingStayNight>  $calendar  'YYYY-MM-DD' => night of the pricing calendar
     * @param array<string, int>|null          $effective 'YYYY-MM-DD' => stay in force today, when read
     * @param array<string, DateOverride>|null $dateRules 'YYYY-MM-DD' => date rule in force; null when not read
     *
     * @return list<PricingStayEntry> the nights with a finding, ascending
     */
    public function plan(
        string $room,
        array $calendar,
        Occupancy $occupancy,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeImmutable $today,
        ?array $effective = null,
        ?array $dateRules = null,
        bool $stayOnly = true,
    ): array {
        $out = [];
        for ($night = Days::normalize($from); $night <= $to; $night = Days::add($night, 1)) {
            $key = Days::key($night);
            $row = $calendar[$key] ?? null;
            $entry = $row === null
                ? new PricingStayEntry($room, $night, [StayFinding::NoPricingData])
                : $this->night($room, $night, $row, $occupancy, $today, $effective[$key] ?? null, $dateRules, $stayOnly);
            if ($entry->findings !== []) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param array<string, DateOverride>|null $dateRules
     */
    private function night(string $room, DateTimeImmutable $night, PricingStayNight $row, Occupancy $occupancy, DateTimeImmutable $today, ?int $effective, ?array $dateRules, bool $stayOnly): PricingStayEntry
    {
        $rule = $dateRules === null ? null : ($dateRules[Days::key($night)] ?? null);
        $stay = $effective ?? $row->minStay;
        $decision = (new NightStayDecision($this->rules, $room, $night, $occupancy, $today))->decide($stay, $dateRules !== null, $rule?->minStay, $stayOnly);
        $masks = $this->openDayMasks($room, $night, $row, $dateRules === null ? null : $rule);
        $findings = $decision->findings;
        if ($masks !== null) {
            $findings[] = StayFinding::OpenDayMasks;
        }
        $hasIssue = array_filter($findings, static fn (StayFinding $f): bool => !$f->isAccepted()) !== [];
        $keep = $hasIssue && $this->keepsReason($dateRules, $rule, $masks !== null, $decision->isOrphan());
        $holiday = $this->rules->holiday($night)?->name;

        return new PricingStayEntry(
            $room,
            $night,
            $findings,
            $stay,
            $holiday,
            $decision->change($stay, $holiday, $keep),
            $masks === null ? null : ArrivalDepartureChange::forPricing($room, $night, StayReason::OpenDay, $masks[0], $masks[1], $keep),
        );
    }

    /**
     * The masks with the night's weekday open when the date rule's masks (when it has a valid
     * departure mask) or the listing's close it; null when nothing closes it or the masks are off.
     *
     * @return array{string, string}|null check-in mask, check-out mask
     */
    private function openDayMasks(string $room, DateTimeImmutable $night, PricingStayNight $row, ?DateOverride $rule): ?array
    {
        if (!$this->rules->openDayApplies($room, $night)) {
            return null;
        }
        [$checkin, $checkout, $enabled] = $rule?->checkoutMask !== null
            ? [$rule->checkinMask, $rule->checkoutMask, true]
            : [$row->checkinMask, $row->checkoutMask, $row->masksEnabled];
        $weekday = Days::weekday($night);
        $closes = array_filter([$checkout, $checkin], static fn (?string $m): bool => $m !== null && $m[$weekday] === '0');
        if ($closes === [] || !$enabled) {
            return null;
        }

        return [self::openWeekday($checkin, $weekday), self::openWeekday($checkout, $weekday)];
    }

    private static function openWeekday(?string $mask, int $weekday): string
    {
        return $mask === null ? '1111111' : substr_replace($mask, '1', $weekday, 1);
    }

    /**
     * The item keeps the date rule's reason when the rule explains something the item does not
     * change: a price, floor or ceiling, masks (unless the item changes them) or a marked reason.
     * Without the date rules read, an item that changes the masks (and is no orphan) keeps it.
     *
     * @param array<string, DateOverride>|null $dateRules
     */
    private function keepsReason(?array $dateRules, ?DateOverride $rule, bool $changesMasks, bool $orphan): bool
    {
        if ($dateRules === null) {
            return $changesMasks && !$orphan;
        }
        if ($rule === null) {
            return false;
        }
        if ($rule->hasPriceRule() || (!$changesMasks && ($rule->checkinMask !== null || $rule->checkoutMask !== null))) {
            return true;
        }
        $reason = mb_strtolower($rule->reason);
        foreach ($this->rules->config()->keepReasonMarkers as $marker) {
            if (str_contains($reason, $marker)) {
                return true;
            }
        }

        return false;
    }
}
