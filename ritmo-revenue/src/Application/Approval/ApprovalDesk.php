<?php

declare(strict_types=1);

namespace Ritmo\Application\Approval;

use Closure;
use DateTimeZone;
use OutOfBoundsException;
use Ritmo\Domain\Operations\LocalTime;
use Ritmo\Domain\Operations\Slot;
use Ritmo\Domain\Operations\SlotKind;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Write\ApprovalConfig;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Db\Repository\PlanApprovalRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Schedule\ScheduledRunRepository;

/**
 * What the approval screen and the REST API do with a plan waiting for approval: a person who
 * holds the approval capability approves all of it or whole groups of it, or refuses it; the
 * decision is recorded with who, when and why, and an approval puts an approved write on the
 * property's schedule at once (the scheduler starts it under the property's run lock, from fresh
 * reads, and writes only what was approved). The caller checks the nonce first.
 */
final class ApprovalDesk
{
    /**
     * @param (Closure(): void)|null $wake asks the scheduler to tick now (WP-Cron spawn)
     */
    public function __construct(
        private readonly PlanApprovalRepository $requests,
        private readonly CapabilityCheck $capabilities,
        private readonly ScheduledRunRepository $slots,
        private readonly OperationEventRepository $events,
        private readonly PropertyRepository $properties,
        private readonly Clock $clock,
        private readonly ?Closure $wake = null,
    ) {
    }

    /**
     * @param list<string>|null $selected entry keys (closed over their groups); null approves everything
     *
     * @throws ApprovalRefused       as ApprovalService::approve()
     * @throws OutOfBoundsException when the request is not of the property
     */
    public function approve(string $propertyId, int $requestId, int $userId, ?array $selected, ApprovalConfig $config, string $note = ''): ApprovalGrant
    {
        $this->belongs($propertyId, $requestId);
        $grant = $this->service($config)->approve($requestId, $userId, $selected, $note);
        $this->events->record($propertyId, OperationEventRepository::APPROVAL, 'pending', 'approved', $note === '' ? sprintf('Request %d approved (%d entries).', $requestId, count($grant->approvedEntries)) : $note, 'user:' . $userId, $this->clock->now());
        $this->schedule($propertyId);

        return $grant;
    }

    /**
     * @throws ApprovalRefused       as ApprovalService::reject()
     * @throws OutOfBoundsException when the request is not of the property
     */
    public function reject(string $propertyId, int $requestId, int $userId, ApprovalConfig $config, string $note = ''): void
    {
        $this->belongs($propertyId, $requestId);
        $this->service($config)->reject($requestId, $userId, $note);
        $this->events->record($propertyId, OperationEventRepository::APPROVAL, 'pending', 'rejected', $note === '' ? sprintf('Request %d refused.', $requestId) : $note, 'user:' . $userId, $this->clock->now());
    }

    /**
     * The requests waiting for a person, with their entries, after ending those whose time is over.
     *
     * @return list<array{id: int, kind: string, plan_day: string, expires_at: string, entries: list<PlanEntry>}>
     */
    public function pending(string $propertyId, ApprovalConfig $config): array
    {
        $service = $this->service($config);
        $service->expireDue($propertyId);
        $out = [];
        foreach ($this->requests->pending($propertyId) as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'kind' => (string) $row['kind'],
                'plan_day' => $row['plan_day']->format('Y-m-d'),
                'expires_at' => $row['expires_at']->format(DATE_ATOM),
                'entries' => $service->entriesOf((int) $row['id']),
            ];
        }

        return $out;
    }

    private function schedule(string $propertyId): void
    {
        $property = $this->properties->find($propertyId) ?? throw new OutOfBoundsException(sprintf('Unknown property "%s".', $propertyId));
        $now = $this->clock->now();
        $local = $now->setTimezone(new DateTimeZone($property->timezone));
        $this->slots->materialise($propertyId, new Slot(SlotKind::ApprovedWrite, $this->clock->today($property->timezone), LocalTime::fromString($local->format('H:i')), $now));
        if ($this->wake !== null) {
            ($this->wake)();
        }
    }

    /**
     * @throws OutOfBoundsException
     */
    private function belongs(string $propertyId, int $requestId): void
    {
        $row = $this->requests->find($requestId);
        if ($row === null || $row['property_id'] !== $propertyId) {
            throw new OutOfBoundsException(sprintf('No request %d for the property.', $requestId));
        }
    }

    private function service(ApprovalConfig $config): ApprovalService
    {
        return new ApprovalService($this->requests, $this->capabilities, $this->clock, $config);
    }
}
