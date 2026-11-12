<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Payload;

use InvalidArgumentException;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\ListingChange;
use Ritmo\Domain\Plan\PercentItem;
use Ritmo\Domain\Plan\PercentRounding;
use Ritmo\Domain\Plan\PlanConfig;
use Ritmo\Domain\Plan\ValidatedPlan;

/**
 * Turns a validated plan into what the pricing system stores, per listing, in plan order:
 *
 * - the date rules to delete first;
 * - the date rules to write: floor and ceiling always as whole percentages over the base (the
 *   floor rounded up, the ceiling down and never under the floor; never a fixed amount), the
 *   property's currency with them, the minimum stay, both weekday masks when either is set (the
 *   other one all open), the reason cut to the configured length, or no reason for an item that
 *   keeps the reason of the rule in force;
 * - the listing amounts.
 *
 * Items whose percentages are already in force are left out (PercentRounding::apply). Nothing is
 * sent: the payload is data for the writer.
 *
 * Reference: validar_plano.montar_payload.
 */
final class PayloadBuilder
{
    private const ALL_OPEN = '1111111';

    /**
     * @param string $pms the PMS name the pricing system knows the listings by
     *
     * @throws InvalidArgumentException on an empty PMS name
     */
    public function __construct(private readonly string $pms, private readonly PlanConfig $config = new PlanConfig())
    {
        if ($pms === '') {
            throw new InvalidArgumentException('The payload needs the PMS name of the listings.');
        }
    }

    /**
     * @param list<RoomType> $rooms the room types of the property, as they are now
     *
     * @throws InvalidArgumentException when the plan is of another property, was not sealed by the
     *                                  Validator, or names a room that is not in the list
     */
    public function build(ValidatedPlan $plan, Property $property, array $rooms): Payload
    {
        if ($plan->plan->propertyId !== $property->id) {
            throw new InvalidArgumentException(sprintf('The plan is for property "%s", not "%s".', $plan->plan->propertyId, $property->id));
        }
        $percent = (new PercentRounding())->apply($plan, $rooms);
        $byCode = [];
        foreach ($rooms as $room) {
            $byCode[$room->code] = $room;
        }

        $writes = [];
        foreach ($percent->items as $item) {
            $writes[$item->item->roomCode][] = $this->override($item, $property->currency);
        }
        $deletions = [];
        foreach ($plan->plan->deletions as $deletion) {
            $deletions[$deletion->roomCode][] = $deletion->date;
        }

        return new Payload(
            $this->calls(CallKind::Delete, $deletions, $byCode),
            $this->calls(CallKind::Write, $writes, $byCode),
            $this->listing($plan->plan->listingChanges, $byCode),
        );
    }

    private function override(PercentItem $percent, string $currency): OverrideWrite
    {
        $item = $percent->item;
        $days = $item->setsDays();

        return new OverrideWrite(
            $item->date,
            $item->keepReason && !$item->setsPrice() ? null : mb_substr($item->reasonText !== '' ? $item->reasonText : $item->reasonCode, 0, $this->config->reasonMaxLength, 'UTF-8'),
            $percent->minPercent,
            $percent->maxPercent,
            $item->minStay,
            $days ? ($item->checkInMask ?? self::ALL_OPEN) : null,
            $days ? ($item->checkOutMask ?? self::ALL_OPEN) : null,
            $currency,
        );
    }

    /**
     * @param array<string, list<mixed>> $entries room code => entries, rooms in plan order
     * @param array<string, RoomType>    $byCode
     *
     * @return list<RoomCall>
     */
    private function calls(CallKind $kind, array $entries, array $byCode): array
    {
        $calls = [];
        foreach ($entries as $code => $list) {
            $calls[] = new RoomCall($kind, (string) $code, $this->room($byCode, (string) $code)->pricingRef, $this->pms, $list);
        }

        return $calls;
    }

    /**
     * @param list<ListingChange>     $changes
     * @param array<string, RoomType> $byCode
     *
     * @return list<ListingWrite>
     */
    private function listing(array $changes, array $byCode): array
    {
        $fields = [];
        foreach ($changes as $change) {
            $fields[$change->roomCode][$change->field->value] = $change->value;
        }
        $writes = [];
        foreach ($fields as $code => $values) {
            $writes[] = new ListingWrite((string) $code, $this->room($byCode, (string) $code)->pricingRef, $this->pms, $values);
        }

        return $writes;
    }

    /**
     * @param array<string, RoomType> $byCode
     */
    private function room(array $byCode, string $code): RoomType
    {
        return $byCode[$code] ?? throw new InvalidArgumentException(sprintf('Unknown room "%s" in the plan.', $code));
    }
}
