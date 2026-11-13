<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Application\Rules\PrivacyCheck;
use OutOfBoundsException;
use Ritmo\Contracts\Logger;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Schedule\OperationStateRepository;

/**
 * Pauses and resumes the scheduled runs of a property (operations spec 3.6). While paused no
 * scheduled slot starts; "Run now" stays available, and a run in progress is never cut in the
 * middle of a write slice. The caller checks the capability (manage rules) and the nonce first.
 */
final class PauseService
{
    public const MAX_REASON = 190;

    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly OperationStateRepository $states,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly OperationEventRepository $events,
    ) {
    }

    /**
     * @param DateTimeImmutable|null $until the first tick after it resumes the property; null: until resumed
     *
     * @throws OutOfBoundsException     when the property does not exist
     * @throws InvalidArgumentException when $until is not in the future
     */
    public function pause(string $propertyId, ?DateTimeImmutable $until, string $reason, int $userId): void
    {
        $this->requireProperty($propertyId);
        if ($until !== null && $until <= $this->clock->now()) {
            throw new InvalidArgumentException('A pause ends in the future.');
        }
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($reason)));
        if (PrivacyCheck::problem($clean, self::MAX_REASON) !== null) {
            throw new InvalidArgumentException('A reason cannot hold an email, a phone number or a document number.');
        }
        $this->states->pause($propertyId, $until, $userId, mb_substr($clean, 0, self::MAX_REASON));
        $this->events->record($propertyId, OperationEventRepository::PAUSE, 'running', $until?->format(DATE_ATOM) ?? 'until_resumed', mb_substr($clean, 0, self::MAX_REASON), 'user:' . $userId, $this->clock->now());
        $this->logger->info('The scheduled runs of a property were paused.', ['property_id' => $propertyId, 'user_id' => $userId, 'until' => $until?->format(DATE_ATOM)]);
    }

    /**
     * @throws OutOfBoundsException when the property does not exist
     */
    public function resume(string $propertyId, int $userId): void
    {
        $this->requireProperty($propertyId);
        $this->states->resume($propertyId);
        $this->events->record($propertyId, OperationEventRepository::PAUSE, 'paused', 'resumed', '', 'user:' . $userId, $this->clock->now());
        $this->logger->info('The scheduled runs of a property were resumed.', ['property_id' => $propertyId, 'user_id' => $userId]);
    }

    private function requireProperty(string $propertyId): void
    {
        if ($this->properties->find($propertyId) === null) {
            throw new OutOfBoundsException(sprintf('Unknown property "%s".', $propertyId));
        }
    }
}
