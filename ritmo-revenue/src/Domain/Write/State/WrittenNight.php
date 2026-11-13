<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\State;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Support\Days;

/**
 * One room night of a write the system accepted: what was sent, what the night held before (read
 * when the run collected), why, and in which group and journal row. The confirmation, the change
 * log and the undo all start from it. Immutable.
 *
 * Listing changes are not nights: they are confirmed by re-reading the listing (docs/VERIFY.md).
 */
final class WrittenNight
{
    public readonly DateTimeImmutable $night;

    public readonly DateTimeImmutable $writtenAt;

    /**
     * @param NightFields  $sent              the fields of the request for this night (empty for a deletion)
     * @param NightFields  $before            what the night held when the run collected
     * @param int|null     $expectedAvailable PMS availability expected on the re-read (units minus the
     *                                        night's bookings) when the write set it
     * @param string       $journalRef        the journal row of the request
     *
     * @throws InvalidArgumentException on an invalid room code, fields of another system, a
     *                                  deletion on the PMS or a write without fields
     */
    public function __construct(
        public readonly Provider $provider,
        public readonly string $roomCode,
        DateTimeImmutable $night,
        public readonly WriteKind $kind,
        public readonly NightFields $sent,
        public readonly NightFields $before,
        DateTimeImmutable $writtenAt,
        public readonly string $groupKey = '',
        public readonly string $reasonCode = '',
        public readonly string $reasonText = '',
        public readonly ?string $orderId = null,
        public readonly string $journalRef = '',
        public readonly ?int $expectedAvailable = null,
    ) {
        if (!Identifier::isCode($roomCode)) {
            throw new InvalidArgumentException(sprintf('Invalid room type code "%s".', $roomCode));
        }
        if ($provider === Provider::Listing) {
            throw new InvalidArgumentException('A listing change is not a night.');
        }
        if (!$sent->of($provider)->equals($sent) || !$before->of($provider)->equals($before)) {
            throw new InvalidArgumentException('The fields of a written night belong to its own system.');
        }
        if ($provider === Provider::Pms && $kind !== WriteKind::Write) {
            throw new InvalidArgumentException('The PMS calendar is only written, never deleted.');
        }
        if ($kind !== WriteKind::Delete && $sent->isEmpty()) {
            throw new InvalidArgumentException('A write sends at least one field.');
        }
        $this->night = Days::normalize($night);
        $this->writtenAt = $writtenAt->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * What the night should hold after the write: the sent fields over what it held (the pricing
     * system merges), nothing after a deletion, only the sent fields after a group.
     */
    public function intended(): NightFields
    {
        return match ($this->kind) {
            WriteKind::Write => $this->before->merge($this->sent),
            WriteKind::Delete => NightFields::none(),
            WriteKind::Rewrite => $this->sent,
        };
    }

    /**
     * "provider|ROOM|YYYY-MM-DD".
     */
    public function key(): string
    {
        return self::keyOf($this->provider, $this->roomCode, $this->night);
    }

    public static function keyOf(Provider $provider, string $room, DateTimeImmutable $night): string
    {
        return $provider->value . '|' . $room . '|' . Days::key($night);
    }
}
