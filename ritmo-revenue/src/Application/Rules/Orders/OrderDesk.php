<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules\Orders;

use Ritmo\Application\Rules\PrivacyCheck;
use Ritmo\Application\Rules\RulesRefused;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Orders\OrderReader;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\Repository\DetectedFreezeRepository;
use Ritmo\Infrastructure\Db\Repository\EditorEventRepository;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Db\Repository\OrderLedgerRepository;
use Ritmo\Infrastructure\Db\Repository\OrderRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;

/**
 * Creates and ends owner orders and releases detected freezes (spec 05, 5.8). Orders are never
 * edited: a copy is a new order that may end the original. An order applies from the next run;
 * nothing here writes to an external system. Every change is an event with who and when.
 */
final class OrderDesk
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly PropertyRepository $properties,
        private readonly RoomTypeRepository $rooms,
        private readonly RuleSetRepository $ruleSets,
        private readonly OrderRepository $orders,
        private readonly OrderLedgerRepository $ledger,
        private readonly DetectedFreezeRepository $freezes,
        private readonly EditorEventRepository $events,
        private readonly OperationEventRepository $operationEvents,
    ) {
    }

    /**
     * @param array<string, mixed> $fields     the order form (OrderForm::toOrder)
     * @param string|null          $typed      the typed property id, for a safety release
     * @param bool                 $mayRelease the person holds ritmo_manage_safety
     * @param int|null             $endOriginal the order this one copies, to end it
     *
     * @return int the new order's id
     *
     * @throws InvalidConfig naming the form fields that do not read
     * @throws RulesRefused  for a safety release refused, a note with guest data or an unknown original
     */
    public function create(string $propertyId, array $fields, int $userId, ?string $typed, bool $mayRelease, ?int $endOriginal = null): int
    {
        $property = $this->properties->find($propertyId) ?? throw new RulesRefused(RulesRefused::UNKNOWN_ORDER);
        $rules = $this->ruleSets->current() ?? RuleSet::defaults();
        $codes = array_map(static fn ($room): string => $room->code, $this->rooms->forProperty($propertyId));
        $order = OrderForm::toOrder($fields, $codes, (float) $rules->get('orders.defaults.target_percentile'));
        $problem = PrivacyCheck::problem((string) $order['note'], OrderForm::NOTE_MAX);
        if ($problem !== null) {
            throw new InvalidConfig('owner order', [new ValidationError('note', ValidationError::FORMAT, $problem)]);
        }
        if (array_intersect($order['releases'], OrderForm::SAFETY_RELEASES) !== []) {
            if (!$mayRelease) {
                throw new RulesRefused(RulesRefused::RELEASE_NOT_ALLOWED);
            }
            if (trim((string) $typed) !== $property->id) {
                throw new RulesRefused(RulesRefused::CONFIRMATION, ['expected' => $property->id]);
            }
        }
        $order['given_on'] = Days::key($this->clock->today($property->timezone));
        (new OrderReader())->read($order + ['id' => 'order-new']);
        if ($endOriginal !== null && $this->ledger->find($propertyId, $endOriginal) === null) {
            throw new RulesRefused(RulesRefused::UNKNOWN_ORDER);
        }

        return $this->db->transaction(function () use ($propertyId, $order, $userId, $endOriginal): int {
            $id = $this->orders->add($propertyId, $order, $userId);
            $now = $this->clock->now();
            $this->events->record($propertyId, EditorEventRepository::ORDER, '', 'order-' . $id, 'Order created', 'user:' . $userId, $now, ['type' => $order['type'], 'releases' => $order['releases'], 'copy_of' => $endOriginal]);
            if ($endOriginal !== null && $this->ledger->end($propertyId, $endOriginal, $now)) {
                $this->events->record($propertyId, EditorEventRepository::ORDER, 'order-' . $endOriginal, '', 'Order ended by its copy', 'user:' . $userId, $now, ['replaced_by' => $id]);
            }

            return $id;
        });
    }

    /**
     * @throws RulesRefused when the order is unknown or not active
     */
    public function end(string $propertyId, int $orderId, int $userId): void
    {
        $this->db->transaction(function () use ($propertyId, $orderId, $userId): void {
            $now = $this->clock->now();
            if (!$this->ledger->end($propertyId, $orderId, $now)) {
                throw new RulesRefused(RulesRefused::UNKNOWN_ORDER);
            }
            $this->events->record($propertyId, EditorEventRepository::ORDER, 'order-' . $orderId, '', 'Order ended', 'user:' . $userId, $now);
        });
    }

    /**
     * A person releases a night frozen because someone else changed it: the plugin may change
     * those nights again from the next run.
     *
     * @throws RulesRefused when the freeze is unknown or already released
     */
    public function releaseFreeze(string $propertyId, int $freezeId, int $userId): void
    {
        $known = array_filter($this->freezes->unreleased($propertyId), static fn ($f): bool => $f->id === $freezeId);
        if ($known === []) {
            throw new RulesRefused(RulesRefused::UNKNOWN_ORDER);
        }
        $this->db->transaction(function () use ($propertyId, $freezeId, $userId): void {
            $now = $this->clock->now();
            $this->freezes->release($freezeId, $userId, $now);
            $this->operationEvents->record($propertyId, OperationEventRepository::FREEZE_RELEASE, 'freeze-' . $freezeId, 'released', 'Frozen nights released by hand', 'user:' . $userId, $now);
        });
    }
}
