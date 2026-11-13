<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Hold;

use DateTimeImmutable;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\FreshCalendar;
use Ritmo\Domain\Plan\IssueSubject;
use Ritmo\Domain\Plan\ListingField;
use Ritmo\Domain\Plan\PercentRounding;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;

/**
 * While a cancellation wave holds the property, the cuts wait; raises and protections go ahead.
 *
 * A cut is: an item whose floor (as the pricing system applies it) is under the floor in force,
 * or whose ceiling is under the current price; a deletion not followed by a rewrite whose floor
 * keeps the floor in force; a listing value lowered. When what is in force is unknown, a floor or
 * a deletion counts as a cut (the safe side: holding only delays). A deletion and the item that
 * writes its night again are held together. On the PMS, a change that opens sales (restriction
 * lifted, shorter stay, more units, channel limit raised) is held on the nights of the cancelled
 * bookings.
 *
 * Reference: AP 06/11 ("subir pela venda nova pode; descer por cancelamento espera a recepção
 * confirmar"), AP 21/10; scope decision D11.
 */
final class CancellationHold
{
    private const STRICTNESS = [
        'none' => 0,
        'no_checkin' => 1,
        'no_checkout' => 1,
        'no_checkin_checkout' => 2,
        'blackout' => 3,
    ];

    public function __construct(
        private readonly WaveConfig $config = new WaveConfig(),
        private readonly PercentRounding $rounding = new PercentRounding(),
    ) {
    }

    /**
     * @param array<string, RoomType> $rooms room code => room type (base, listing values)
     */
    public function apply(Plan $plan, ?WaveHold $hold, array $rooms, ?FreshCalendar $calendar, DateTimeImmutable $now): HoldResult
    {
        if ($hold === null || !$hold->isActive($now, $this->config->holdHours)) {
            return new HoldResult($plan, []);
        }
        $held = [];
        $heldNights = [];
        foreach ($plan->deletions as $i => $deletion) {
            $rewrite = $this->rewriteOf($plan, $deletion->key());
            $floorInForce = self::floorInForce($rewrite, $calendar, $deletion->roomCode, $deletion->date);
            $keeps = $rewrite !== null && $floorInForce !== null && ($this->floorOf($rewrite, $rooms) ?? -1.0) >= $floorInForce;
            if ($this->inScope($hold, $deletion->roomCode, $deletion->date) && !$keeps) {
                $held[] = new HeldEntry(IssueSubject::Deletion, $i, $deletion->roomCode, $deletion->date, 'deleting the date rule frees a lower price');
                $heldNights[$deletion->key()] = true;
            }
        }
        foreach ($plan->items as $i => $item) {
            $why = isset($heldNights[$item->key()]) ? 'rewrite of a held deletion' : $this->cutOf($item, $rooms, $calendar);
            if ($why !== null && ($this->inScope($hold, $item->roomCode, $item->date) || isset($heldNights[$item->key()]))) {
                $held[] = new HeldEntry(IssueSubject::Item, $i, $item->roomCode, $item->date, $why);
                $heldNights[$item->key()] = true;
            }
        }
        foreach ($plan->deletions as $i => $deletion) {
            if (isset($heldNights[$deletion->key()]) && !self::listed($held, IssueSubject::Deletion, $i)) {
                $held[] = new HeldEntry(IssueSubject::Deletion, $i, $deletion->roomCode, $deletion->date, 'deletion of a held rewrite');
            }
        }
        foreach ($plan->listingChanges as $i => $change) {
            $room = $rooms[$change->roomCode] ?? null;
            $current = $room === null ? null : match ($change->field) {
                ListingField::Min => $room->min,
                ListingField::Base => $room->base,
                ListingField::Max => $room->max,
            };
            if ($current === null || $change->value < $current) {
                $held[] = new HeldEntry(IssueSubject::Listing, $i, $change->roomCode, null, 'a lower listing value');
            }
        }

        return new HoldResult(
            $plan->without(self::indexes($held, IssueSubject::Item), self::indexes($held, IssueSubject::Deletion), self::indexes($held, IssueSubject::Listing)),
            $held,
        );
    }

    /**
     * True when a PMS write of the night opens sales and a wave holds that night.
     *
     * @param NightFields $before what the night holds now
     * @param NightFields $sent   the PMS fields to write
     */
    public function holdsPms(?WaveHold $hold, string $room, DateTimeImmutable $night, NightFields $before, NightFields $sent, DateTimeImmutable $now): bool
    {
        return $hold !== null && $hold->isActive($now, $this->config->holdHours) && $hold->coversNight($room, $night) && self::opensSales($before, $sent);
    }

    public static function opensSales(NightFields $before, NightFields $sent): bool
    {
        $lower = static fn (string $field): bool => $sent->has($field) && $before->has($field) && (float) $sent->get($field) < (float) $before->get($field);
        $higher = static fn (string $field): bool => $sent->has($field) && (float) $sent->get($field) > (float) ($before->get($field) ?? 0);
        $restriction = $sent->get(ChangeField::PMS_RESTRICTION);
        $was = $before->get(ChangeField::PMS_RESTRICTION) ?? Restriction::None->value;
        $lifted = $restriction !== null && (self::STRICTNESS[(string) $restriction] ?? 0) < (self::STRICTNESS[(string) $was] ?? 0);
        $limits = array_filter(ChangeField::CHANNELS, static fn (string $c): bool => $higher(ChangeField::channelLimit($c)));

        return $lifted || $lower(ChangeField::PMS_MIN_STAY) || $higher(ChangeField::PMS_AVAILABLE) || $limits !== [];
    }

    private function inScope(WaveHold $hold, string $room, DateTimeImmutable $night): bool
    {
        return $this->config->scope === HoldScope::Property || $hold->coversNight($room, $night);
    }

    /**
     * @param array<string, RoomType> $rooms
     */
    private function cutOf(PlanItem $item, array $rooms, ?FreshCalendar $calendar): ?string
    {
        $room = $rooms[$item->roomCode] ?? null;
        if ($room === null) {
            return 'unknown room';
        }
        $percent = $this->rounding->convert($item, $room);
        $floor = $percent->minAmount();
        $floorInForce = self::floorInForce($item, $calendar, $item->roomCode, $item->date);
        if ($floor !== null && ($floorInForce === null || $floor < $floorInForce - 0.005)) {
            return 'a lower floor';
        }
        $ceiling = $percent->maxAmount();
        $prices = array_filter([$item->before?->price, $calendar?->night($item->roomCode, $item->date)?->price], static fn (?float $p): bool => $p !== null);
        if ($ceiling !== null && ($prices === [] || $ceiling < max($prices) - 0.005)) {
            return 'a ceiling under the current price';
        }

        return null;
    }

    /**
     * @param array<string, RoomType> $rooms
     */
    private function floorOf(PlanItem $item, array $rooms): ?float
    {
        $room = $rooms[$item->roomCode] ?? null;

        return $room === null ? null : $this->rounding->convert($item, $room)->minAmount();
    }

    /**
     * The highest floor in force known for the night: the item's record, else the fresh calendar.
     */
    private static function floorInForce(?PlanItem $item, ?FreshCalendar $calendar, string $room, DateTimeImmutable $night): ?float
    {
        $floors = array_filter([$item?->before?->minPrice, $calendar?->night($room, $night)?->floor], static fn (?float $f): bool => $f !== null);

        return $floors === [] ? null : max($floors);
    }

    private function rewriteOf(Plan $plan, string $key): ?PlanItem
    {
        foreach ($plan->items as $item) {
            if ($item->key() === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param list<HeldEntry> $held
     */
    private static function listed(array $held, IssueSubject $subject, int $index): bool
    {
        return in_array($index, self::indexes($held, $subject), true);
    }

    /**
     * @param list<HeldEntry> $held
     *
     * @return list<int>
     */
    private static function indexes(array $held, IssueSubject $subject): array
    {
        return array_values(array_map(static fn (HeldEntry $h): int => $h->index, array_filter($held, static fn (HeldEntry $h): bool => $h->subject === $subject)));
    }
}
