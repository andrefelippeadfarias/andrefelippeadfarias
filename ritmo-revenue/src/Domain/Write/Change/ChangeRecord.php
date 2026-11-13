<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Change;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\Provider;

/**
 * One field of one room night (or listing) changed by the plugin and confirmed: before, after,
 * why, who, and how to undo the night. Immutable; one row of the change log.
 *
 * A percentage keeps its amount over the base of the day it was written ("floor 1,051 (-29 %)"),
 * so the record reads the same after the base changes.
 *
 * Reference: SKILL.md (registro de mudanças: Mudança | Onde | Antes | Depois | Como desfazer).
 */
final class ChangeRecord
{
    public const ACTOR_AUTONOMOUS = 'autonomous';
    public const ACTOR_SYSTEM = 'system';

    /** Value of the RULE field: the date rule exists, or not. */
    public const PRESENT = 'present';
    public const ABSENT = 'absent';

    public readonly ?DateTimeImmutable $night;

    public readonly DateTimeImmutable $createdAt;

    public readonly ?DateTimeImmutable $undoneAt;

    /**
     * @param string      $actor        ACTOR_AUTONOMOUS, ACTOR_SYSTEM or "user:<id>" (who approved or asked for the undo)
     * @param int|null    $undoOf       the record this change undid
     * @param string      $confirmLevel the confirmation level reached when it was recorded ("stored", "live")
     *
     * @throws InvalidArgumentException on an invalid room code, an unknown field, a field of another
     *                                  system, a night missing (or given for a listing) or an invalid actor
     */
    public function __construct(
        public readonly string $propertyId,
        public readonly Provider $provider,
        public readonly string $roomCode,
        ?DateTimeImmutable $night,
        public readonly string $field,
        public readonly int|float|string|null $oldValue,
        public readonly int|float|string|null $newValue,
        public readonly UndoAction $undo,
        public readonly string $actor,
        DateTimeImmutable $createdAt,
        public readonly ?float $oldAmount = null,
        public readonly ?float $newAmount = null,
        public readonly string $reasonCode = '',
        public readonly string $reasonText = '',
        public readonly ?string $orderId = null,
        public readonly ?int $runId = null,
        public readonly string $groupKey = '',
        public readonly ?int $undoOf = null,
        public readonly string $confirmLevel = 'stored',
        public readonly ?int $id = null,
        ?DateTimeImmutable $undoneAt = null,
    ) {
        if (!Identifier::isCode($roomCode) || !Identifier::isCode($propertyId)) {
            throw new InvalidArgumentException('Invalid property or room code.');
        }
        $own = $field === ChangeField::RULE ? Provider::Pricing : ChangeField::providerOf($field);
        if ($own !== $provider) {
            throw new InvalidArgumentException(sprintf('The field "%s" is not a field of the %s.', $field, $provider->value));
        }
        if (($night === null) !== ($provider === Provider::Listing)) {
            throw new InvalidArgumentException('A night change needs its night; a listing change has none.');
        }
        if (preg_match('/^(autonomous|system|user:[1-9][0-9]*)$/', $actor) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid actor "%s".', $actor));
        }
        $utc = new DateTimeZone('UTC');
        $this->night = $night === null ? null : Days::normalize($night);
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->undoneAt = $undoneAt?->setTimezone($utc);
    }

    public static function userActor(int $userId): string
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('A user id is positive.');
        }

        return 'user:' . $userId;
    }

    /**
     * What the change is about: "provider|ROOM|YYYY-MM-DD", or "listing|ROOM" for a listing value.
     */
    public function subjectKey(): string
    {
        return $this->provider->value . '|' . $this->roomCode . ($this->night === null ? '' : '|' . Days::key($this->night));
    }

    public function isUndone(): bool
    {
        return $this->undoneAt !== null;
    }
}
