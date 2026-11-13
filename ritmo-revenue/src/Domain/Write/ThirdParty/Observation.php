<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\ThirdParty;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\State\WrittenNight;

/**
 * What one read found in one room night of one system: a run's read (origin "observed") or the
 * read-back after a write of the plugin (origin "written"). The next run compares against the
 * latest observation of the night. Immutable.
 */
final class Observation
{
    public const OBSERVED = 'observed';
    public const WRITTEN = 'written';

    public readonly DateTimeImmutable $night;

    public readonly DateTimeImmutable $observedAt;

    /**
     * @throws InvalidArgumentException on an invalid room, a listing, fields of another system or an unknown origin
     */
    public function __construct(
        public readonly Provider $provider,
        public readonly string $roomCode,
        DateTimeImmutable $night,
        public readonly NightFields $fields,
        DateTimeImmutable $observedAt,
        public readonly string $origin = self::OBSERVED,
    ) {
        if (!Identifier::isCode($roomCode) || $provider === Provider::Listing) {
            throw new InvalidArgumentException('An observation is of a room night of the pricing system or the PMS.');
        }
        if (!$fields->of($provider)->equals($fields)) {
            throw new InvalidArgumentException('An observation holds the fields of its own system only.');
        }
        if ($origin !== self::OBSERVED && $origin !== self::WRITTEN) {
            throw new InvalidArgumentException(sprintf('Unknown origin "%s".', $origin));
        }
        $this->night = Days::normalize($night);
        $this->observedAt = $observedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function key(): string
    {
        return WrittenNight::keyOf($this->provider, $this->roomCode, $this->night);
    }
}
