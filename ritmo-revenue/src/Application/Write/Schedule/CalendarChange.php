<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Schedule;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Calendar\Stay\ArrivalDepartureChange;
use Ritmo\Domain\Calendar\Stay\ChangeTarget;
use Ritmo\Domain\Calendar\Stay\StayChange;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;

/**
 * One PMS calendar change of a write run: a range of nights of one room and the values to set
 * (availability, minimum and maximum stay, arrival/departure restriction, channel limits), with
 * the minimum stay read when it was planned (a night that changed since is skipped) and whether
 * the pricing system also pushes these values to the PMS (then the pricing step goes first).
 * $expectedAvailable is the availability the PMS should report afterwards (net of bookings: it
 * reads back the units left, not the units written); without it the value written is compared.
 * Never a price.
 */
final class CalendarChange
{
    private const RESTRICTIONS = [
        'none' => 'none',
        'blackout' => 'blackout',
        'no_checkin' => 'noCheckIn',
        'no_checkout' => 'noCheckOut',
        'no_checkin_checkout' => 'noCheckInOrCheckOut',
    ];

    public readonly DateTimeImmutable $from;

    public readonly DateTimeImmutable $to;

    /**
     * @param array<string, int|null>         $channels channel => booking limit (null lifts it)
     * @param array<string, int|string|null> $read     values read before the plan of the stay fields it sets, in the
     *                                                 API's fields (minStay, maxStay, override; null: read as none)
     *
     * @throws InvalidArgumentException on a range that ends before it starts or that sets nothing
     */
    public function __construct(
        public readonly string $roomCode,
        public readonly string $roomId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        public readonly ?int $minStay = null,
        public readonly ?int $maxStay = null,
        public readonly ?Restriction $restriction = null,
        public readonly ?int $available = null,
        public readonly array $channels = [],
        public readonly ?int $minStayBefore = null,
        public readonly bool $alsoPricing = false,
        public readonly string $reason = '',
        public readonly ?int $expectedAvailable = null,
        public readonly array $read = [],
    ) {
        $this->from = Days::normalize($from);
        $this->to = Days::normalize($to);
        if ($this->to < $this->from) {
            throw new InvalidArgumentException('A calendar change ends before it starts.');
        }
        if ($minStay === null && $maxStay === null && $restriction === null && $available === null && $channels === []) {
            throw new InvalidArgumentException('A calendar change sets at least one value.');
        }
    }

    /**
     * The PMS changes of the stay rules (M2) as calendar changes of one night each; changes meant
     * for the pricing system are left out (they are plan items).
     *
     * @param list<StayChange|ArrivalDepartureChange> $changes
     * @param array<string, RoomType>                 $rooms   room code => room
     *
     * @return list<self>
     *
     * @throws InvalidArgumentException on a change of an unknown room
     */
    public static function fromStay(array $changes, array $rooms): array
    {
        $out = [];
        foreach ($changes as $change) {
            if ($change->target !== ChangeTarget::Pms) {
                continue;
            }
            $room = $rooms[$change->roomCode] ?? throw new InvalidArgumentException(sprintf('Unknown room "%s" in the PMS changes.', $change->roomCode));
            $out[] = $change instanceof StayChange
                ? new self($room->code, $room->pmsRef, $change->night, $change->night, $change->minStay, $change->maxStay, null, null, [], $change->minStayBefore, $change->alsoPricing, $change->reason->value)
                : new self($room->code, $room->pmsRef, $change->night, $change->night, null, null, $change->after, null, [], null, $change->alsoPricing, $change->reason->value);
        }

        return $out;
    }

    /**
     * Every stay value read before the plan that the PMS must still hold right before the write:
     * the minimum stay read ($minStayBefore) and the other values read ($read).
     *
     * @return array<string, int|string|null> API field => value read (null: none)
     */
    public function readBefore(): array
    {
        return $this->minStayBefore === null ? $this->read : ['minStay' => $this->minStayBefore] + $this->read;
    }

    /**
     * The value this change sets in one of the API's stay fields (null: it does not set it).
     */
    public function sets(string $field): int|string|null
    {
        return match ($field) {
            'minStay' => $this->minStay,
            'maxStay' => $this->maxStay,
            'override' => $this->restriction === null ? null : self::apiRestriction($this->restriction),
            default => null,
        };
    }

    /**
     * A restriction as the PMS API names it.
     */
    public static function apiRestriction(Restriction $restriction): string
    {
        return self::RESTRICTIONS[$restriction->value];
    }

    /**
     * The range in the PMS API's fields.
     *
     * @return array<string, mixed>
     */
    public function range(): array
    {
        $range = ['roomId' => $this->roomId, 'from' => Days::key($this->from), 'to' => Days::key($this->to)];
        foreach (['minStay' => $this->minStay, 'maxStay' => $this->maxStay, 'numAvail' => $this->available] as $field => $value) {
            if ($value !== null) {
                $range[$field] = $value;
            }
        }
        if ($this->restriction !== null) {
            $range['override'] = self::apiRestriction($this->restriction);
        }
        if ($this->channels !== []) {
            $range['channels'] = $this->channels;
        }

        return $range;
    }

    /**
     * @return list<string> the nights of the range
     */
    public function nights(): array
    {
        $nights = [];
        for ($night = $this->from; $night <= $this->to; $night = Days::add($night, 1)) {
            $nights[] = Days::key($night);
        }

        return $nights;
    }
}
