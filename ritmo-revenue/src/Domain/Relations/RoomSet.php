<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\Config\Reader;

/**
 * A set of room types of one property, given by code or by tags.
 *
 * With tags it means every room of the property that has all the tags, in the property's room
 * order, the target of the relation excluded.
 */
final class RoomSet
{
    private const MAX_ROOMS = 50;
    private const MAX_TAGS = 20;

    /**
     * @param list<string> $rooms room type codes (empty when the set is given by tags)
     * @param list<string> $tags  tag slugs (empty when the set is given by codes)
     */
    private function __construct(
        public readonly array $rooms,
        public readonly array $tags,
    ) {
    }

    /**
     * @param list<string> $rooms
     */
    public static function ofRooms(array $rooms): self
    {
        return new self(array_values($rooms), []);
    }

    /**
     * @param list<string> $tags
     */
    public static function ofTags(array $tags): self
    {
        return new self([], array_values($tags));
    }

    public static function read(Reader $reader): self
    {
        $reader->rejectUnknown(['rooms', 'room_tags']);
        if ($reader->has('rooms') === $reader->has('room_tags')) {
            $reader->error('rooms', ValidationError::REQUIRED, 'give either "rooms" or "room_tags"');

            return new self([], []);
        }
        if ($reader->has('rooms')) {
            $rooms = $reader->texts('rooms', Identifier::CODE_PATTERN, self::MAX_ROOMS);
            if ($rooms === []) {
                $reader->error('rooms', ValidationError::RANGE, 'must name at least one room');
            }

            return new self($rooms, []);
        }
        $tags = $reader->texts('room_tags', Identifier::SLUG_PATTERN, self::MAX_TAGS);
        if ($tags === []) {
            $reader->error('room_tags', ValidationError::RANGE, 'must name at least one tag');
        }

        return new self([], $tags);
    }

    public function byTags(): bool
    {
        return $this->rooms === [];
    }

    /**
     * The codes of the set, the target excluded. Rooms of other properties never match a tag set.
     *
     * @param list<RoomType> $rooms every room type, in the property's room order
     *
     * @return list<string>
     */
    public function resolve(string $target, string $propertyId, array $rooms): array
    {
        if (!$this->byTags()) {
            return array_values(array_filter($this->rooms, static fn (string $code): bool => $code !== $target));
        }
        $codes = [];
        foreach ($rooms as $room) {
            if ($room->propertyId === $propertyId && $room->code !== $target && $room->hasAllTags($this->tags)) {
                $codes[] = $room->code;
            }
        }

        return $codes;
    }

    /**
     * @return array{rooms: list<string>}|array{room_tags: list<string>}
     */
    public function toArray(): array
    {
        return $this->byTags() ? ['room_tags' => $this->tags] : ['rooms' => $this->rooms];
    }
}
