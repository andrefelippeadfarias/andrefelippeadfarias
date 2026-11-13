<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules\Orders;

use DateTimeImmutable;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Orders\OrderReader;
use Ritmo\Domain\Orders\OrderType;
use Ritmo\Domain\Orders\OwnerOrder;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Write\ThirdParty\DetectedFreeze;
use Ritmo\Infrastructure\Db\Repository\DetectedFreezeRepository;
use Ritmo\Infrastructure\Db\Repository\OrderLedgerRepository;

/**
 * The orders screen's lists (spec 05, 5.8): orders in force today or later, freezes (orders made
 * by the plugin for a manual change, and detected freezes not released), and orders ended or
 * past in the last 180 days. A stored order that does not read is listed with its problem: the
 * runs ignore it.
 */
final class OrderBoard
{
    public const ENDED_DAYS = 180;

    public function __construct(
        private readonly Clock $clock,
        private readonly OrderLedgerRepository $ledger,
        private readonly DetectedFreezeRepository $freezes,
    ) {
    }

    /**
     * @return list<array{id: int, order: OwnerOrder|null, problem: string, created_by: int, created_at: DateTimeImmutable, ended_at: DateTimeImmutable|null}>
     */
    public function active(string $propertyId, DateTimeImmutable $today): array
    {
        return array_values(array_filter($this->read($this->ledger->active($propertyId, $today)), static fn (array $r): bool => $r['order']?->type !== OrderType::Manual));
    }

    /**
     * @return array{manual: list<array{id: int, order: OwnerOrder|null, problem: string, created_by: int, created_at: DateTimeImmutable, ended_at: DateTimeImmutable|null}>, detected: list<DetectedFreeze>}
     */
    public function freezes(string $propertyId, DateTimeImmutable $today): array
    {
        return [
            'manual' => array_values(array_filter($this->read($this->ledger->active($propertyId, $today)), static fn (array $r): bool => $r['order']?->type === OrderType::Manual)),
            'detected' => $this->freezes->unreleased($propertyId),
        ];
    }

    /**
     * @return list<array{id: int, order: OwnerOrder|null, problem: string, created_by: int, created_at: DateTimeImmutable, ended_at: DateTimeImmutable|null}>
     */
    public function ended(string $propertyId, DateTimeImmutable $today): array
    {
        return $this->read($this->ledger->ended($propertyId, $today, $this->clock->now()->modify(sprintf('-%d days', self::ENDED_DAYS))));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id: int, order: OwnerOrder|null, problem: string, created_by: int, created_at: DateTimeImmutable, ended_at: DateTimeImmutable|null}>
     */
    private function read(array $rows): array
    {
        $reader = new OrderReader();

        return array_map(static function (array $row) use ($reader): array {
            try {
                $order = $reader->read($row['params'] + ['id' => 'order-' . $row['id']]);
                $problem = '';
            } catch (InvalidConfig $invalid) {
                $order = null;
                $problem = (string) ($invalid->errors()[0] ?? 'unreadable');
            }

            return [
                'id' => (int) $row['id'],
                'order' => $order,
                'problem' => $problem,
                'created_by' => (int) $row['created_by'],
                'created_at' => $row['created_at'],
                'ended_at' => $row['status'] === OrderLedgerRepository::ENDED ? $row['updated_at'] : null,
            ];
        }, $rows);
    }
}
