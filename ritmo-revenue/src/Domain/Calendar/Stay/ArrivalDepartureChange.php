<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;

/**
 * An arrival/departure restriction to write on one night of a room, ready for the milestone that
 * writes (nothing here sends it).
 *
 * For the PMS it is the restriction before and after (never a blackout: that is availability).
 * For the pricing system it is the pair of weekday masks of the date override (seven characters
 * 0/1, Monday to Sunday). alsoPricing on a PMS change says the pricing system pushes arrival and
 * departure to the PMS for the room, so the same restriction must be written there too.
 */
final class ArrivalDepartureChange
{
    public readonly DateTimeImmutable $night;

    /**
     * @throws InvalidArgumentException on a change that does not fit its target
     */
    private function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $night,
        public readonly ChangeTarget $target,
        public readonly StayReason $reason,
        public readonly ?Restriction $before,
        public readonly ?Restriction $after,
        public readonly ?string $checkinMask,
        public readonly ?string $checkoutMask,
        public readonly bool $alsoPricing,
        public readonly ?OrphanKind $orphan,
        public readonly ?OrphanProof $proof,
        public readonly bool $keepReason,
    ) {
        $this->night = Days::normalize($night);
    }

    /**
     * @throws InvalidArgumentException when a blackout is involved or nothing changes
     */
    public static function forPms(
        string $roomCode,
        DateTimeImmutable $night,
        StayReason $reason,
        Restriction $before,
        Restriction $after,
        bool $alsoPricing = false,
        ?OrphanProof $proof = null,
    ): self {
        if ($before === Restriction::Blackout || $after === Restriction::Blackout) {
            throw new InvalidArgumentException('A blackout is availability, not an arrival/departure rule.');
        }
        if ($before === $after) {
            throw new InvalidArgumentException('An arrival/departure change must change the restriction.');
        }

        return new self($roomCode, $night, ChangeTarget::Pms, $reason, $before, $after, null, null, $alsoPricing, $proof === null ? null : OrphanKind::ClosedDay, $proof, false);
    }

    /**
     * @throws InvalidArgumentException on an invalid mask
     */
    public static function forPricing(
        string $roomCode,
        DateTimeImmutable $night,
        StayReason $reason,
        string $checkinMask,
        string $checkoutMask,
        bool $keepReason = false,
    ): self {
        if (!DateOverride::isValidMask($checkinMask) || !DateOverride::isValidMask($checkoutMask)) {
            throw new InvalidArgumentException('A weekday mask has seven characters, each 0 or 1, Monday to Sunday.');
        }

        return new self($roomCode, $night, ChangeTarget::Pricing, $reason, null, null, $checkinMask, $checkoutMask, false, null, null, $keepReason);
    }

    /**
     * The fields that make two changes the same write.
     */
    public function identity(): string
    {
        return implode('|', ['arrival', $this->target->value, $this->roomCode, Days::key($this->night), $this->after?->value, $this->checkinMask, $this->checkoutMask]);
    }
}
