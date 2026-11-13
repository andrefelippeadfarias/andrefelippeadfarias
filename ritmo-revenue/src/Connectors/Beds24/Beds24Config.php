<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Beds24;

use InvalidArgumentException;
use Ritmo\Connectors\Support\BaseUrl;
use Ritmo\Domain\Model\Identifier;

/**
 * Everything the Beds24 connector needs to know about one site: the credential, which PMS
 * room is which room type, and which properties of the account to read.
 */
final class Beds24Config
{
    public const DEFAULT_MINIMUM_CREDIT = 5.0;

    public readonly string $baseUrl;

    /** @var array<string, string> */
    public readonly array $roomMap;

    /** @var list<string> */
    public readonly array $propertyIds;

    /** @var array<string, int> Beds24 room id => units of the room (the bound of an availability write) */
    public readonly array $roomUnits;

    /**
     * @param array<int|string, string> $roomMap       Beds24 room id => room type code (RoomTypeRepository::pmsMap())
     * @param list<int|string>          $propertyIds   Beds24 property ids to read bookings of; empty for every
     *                                                 property the credential can see
     * @param string|null               $baseUrl       replacement for the API base URL; accepted only while RITMO_DEV is true
     * @param float                     $minimumCredit five-minute credit below which the connector stops with
     *                                                 RateLimited instead of sending a request; 0 turns the check off
     * @param array<int|string, int>    $roomUnits     Beds24 room id => units; availability is written only for these rooms
     *
     * @throws InvalidArgumentException on an id that is not a whole number, a room code that is not a code, a
     *                                  negative credit or a base URL that may not be used
     */
    public function __construct(
        public readonly Beds24Credentials $credentials,
        array $roomMap = [],
        array $propertyIds = [],
        ?string $baseUrl = null,
        public readonly float $minimumCredit = self::DEFAULT_MINIMUM_CREDIT,
        array $roomUnits = [],
    ) {
        $map = [];
        foreach ($roomMap as $roomId => $code) {
            if (!is_string($code) || !Identifier::isCode($code)) {
                throw new InvalidArgumentException('Every entry of the room map needs a valid room type code.');
            }
            $map[self::id($roomId, 'room')] = $code;
        }

        $properties = [];
        foreach ($propertyIds as $propertyId) {
            $properties[] = self::id($propertyId, 'property');
        }

        if (!is_finite($minimumCredit) || $minimumCredit < 0) {
            throw new InvalidArgumentException('The minimum credit cannot be negative.');
        }

        $units = [];
        foreach ($roomUnits as $roomId => $count) {
            if (!is_int($count) || $count < 1) {
                throw new InvalidArgumentException('A room has at least one unit.');
            }
            $units[self::id($roomId, 'room')] = $count;
        }

        $this->roomMap = $map;
        $this->roomUnits = $units;
        $this->propertyIds = array_values(array_unique($properties));
        $this->baseUrl = BaseUrl::resolve(Beds24Connector::BASE_URL, $baseUrl);
    }

    /**
     * A Beds24 id as the text of a positive whole number.
     *
     * @throws InvalidArgumentException
     */
    public static function id(mixed $value, string $what): string
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');
        if (preg_match('/^[1-9][0-9]{0,17}$/', $text) !== 1) {
            throw new InvalidArgumentException(sprintf('A Beds24 %s id is a positive whole number.', $what));
        }

        return $text;
    }
}
