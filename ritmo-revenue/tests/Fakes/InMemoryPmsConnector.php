<?php

declare(strict_types=1);

namespace Ritmo\Tests\Fakes;

use DateTimeImmutable;
use Ritmo\Contracts\ConnectionStatus;
use Ritmo\Contracts\PmsConnector;
use Ritmo\Contracts\Support\PmsWritesUnavailable;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;

/**
 * A PmsConnector over data held in memory, with the semantics of the contract (and of the
 * Beds24 connector): every status, inclusive ranges, nights ascending. For tests of whatever
 * consumes a PMS; it records which reads were made.
 */
final class InMemoryPmsConnector implements PmsConnector
{
    use PmsWritesUnavailable;

    /** @var list<string> */
    private array $calls = [];

    /**
     * @param list<BookingRecord>                $bookings
     * @param array<string, list<InventoryNight>> $inventory PMS room ref => nights (any order)
     */
    public function __construct(
        private readonly array $bookings = [],
        private readonly array $inventory = [],
        private readonly string $id = 'memory-pms',
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function testConnection(): ConnectionStatus
    {
        $this->calls[] = 'testConnection';

        return new ConnectionStatus(true, 'In-memory PMS.', ['bookings' => count($this->bookings), 'rooms' => count($this->inventory)]);
    }

    public function readBookingsByStay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->calls[] = 'readBookingsByStay';
        $range = new DateRange($from, $to);

        return array_values(array_filter($this->bookings, static fn (BookingRecord $b): bool => $b->nights()->intersect($range) !== null));
    }

    public function readBookingsModifiedSince(DateTimeImmutable $instant): array
    {
        $this->calls[] = 'readBookingsModifiedSince';

        return array_values(array_filter($this->bookings, static function (BookingRecord $b) use ($instant): bool {
            $changedAt = $b->modifiedAt ?? $b->createdAt;

            return $changedAt === null || $changedAt >= $instant;
        }));
    }

    public function readInventory(array $roomRefs, DateTimeImmutable $from, DateTimeImmutable $to, bool $withPrices = false): array
    {
        $this->calls[] = 'readInventory';
        $range = new DateRange($from, $to);

        $out = [];
        foreach (array_unique(array_map('strval', $roomRefs)) as $ref) {
            $nights = [];
            foreach ($this->inventory[$ref] ?? [] as $night) {
                if ($range->contains($night->date)) {
                    $nights[Days::key($night->date)] = $withPrices
                        ? $night
                        : new InventoryNight($night->date, $night->available, $night->minStay, $night->maxStay, $night->restriction);
                }
            }
            ksort($nights);
            $out[$ref] = array_values($nights);
        }

        return $out;
    }

    /**
     * Names of the read methods called, in order.
     *
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
