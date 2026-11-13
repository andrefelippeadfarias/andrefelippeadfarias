<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Gate;

use Closure;
use DateTimeImmutable;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;

/**
 * What the gate needs to know about the run asking for a token, all of it read in this run: the
 * property as it is now (its mode), its rooms, the instant the calendars were read, tonight of
 * each room, whether the licence allows writing, whether the run still holds the site's write
 * lock, and who may approve.
 */
final class WriteContext
{
    /** @var array<string, RoomType> */
    public readonly array $rooms;

    /**
     * @param list<RoomType>              $rooms
     * @param array<string, TonightState> $tonight   room code => tonight as read in this run
     * @param Closure(): bool             $lockHeld  true while this run holds the site's write lock (heartbeat)
     * @param Closure(int): bool          $canApprove true when the user holds the approval capability now
     */
    public function __construct(
        public readonly Property $property,
        array $rooms,
        public readonly DateTimeImmutable $readAt,
        public readonly array $tonight,
        public readonly Closure $lockHeld,
        public readonly string $pricingPms,
        public readonly bool $licenceAllowsWrites = true,
        public readonly ?Closure $canApprove = null,
    ) {
        $byCode = [];
        foreach ($rooms as $room) {
            if ($room->propertyId === $property->id) {
                $byCode[$room->code] = $room;
            }
        }
        $this->rooms = $byCode;
    }

    /**
     * @return array<string, float> room code => listing base
     */
    public function bases(): array
    {
        return array_map(static fn (RoomType $room): float => $room->base, $this->rooms);
    }

    public function userCanApprove(int $userId): bool
    {
        return $this->canApprove !== null && ($this->canApprove)($userId) === true;
    }
}
