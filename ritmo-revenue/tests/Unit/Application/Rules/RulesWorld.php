<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Rules;

use Ritmo\Application\Rules\Orders\OrderBoard;
use Ritmo\Application\Rules\Orders\OrderDesk;
use Ritmo\Application\Rules\PendingApprovals;
use Ritmo\Application\Rules\RuleChecks;
use Ritmo\Application\Rules\RuleSetEditor;
use Ritmo\Application\Rules\VersionHistory;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Infrastructure\Db\Migration\Migrations;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\DetectedFreezeRepository;
use Ritmo\Infrastructure\Db\Repository\EditorEventRepository;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Db\Repository\OrderLedgerRepository;
use Ritmo\Infrastructure\Db\Repository\OrderRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\MovableClock;

/**
 * A site with one property and two rooms on in-memory SQLite, the rules at version 1 (the
 * neutral defaults), and the rules and orders use cases wired as the integration would.
 * Requests waiting for approval are counted by a stand-in that records what was closed.
 */
final class RulesWorld
{
    public const PROPERTY = 'inn-1';

    public readonly PdoDatabase $db;
    public readonly MovableClock $clock;
    public readonly RuleSetRepository $ruleSets;
    public readonly EditorEventRepository $events;
    public readonly RuleSetEditor $editor;
    public readonly VersionHistory $history;
    public readonly OrderDesk $orders;
    public readonly OrderBoard $board;
    public readonly DetectedFreezeRepository $freezes;
    public readonly OperationEventRepository $operationEvents;
    public readonly PendingApprovals $approvals;

    public function __construct()
    {
        $this->db = PdoDatabase::inMemory();
        (new Migrator($this->db, new ArrayStore(), Migrations::all()))->migrate();
        $this->clock = new MovableClock('2026-11-13T13:00:00Z');
        $properties = new PropertyRepository($this->db, $this->clock);
        $properties->save(new Property(self::PROPERTY, 'Inn One', 'America/Sao_Paulo', 'BRL'));
        $rooms = new RoomTypeRepository($this->db, $this->clock);
        foreach (['room-a' => 'Room A', 'room-b' => 'Room B'] as $code => $name) {
            $rooms->save(new RoomType(self::PROPERTY, $code, $name, 2, 'pms-' . $code, 'pl-' . $code, 600.0, 400.0, 1200.0, 400.0));
        }
        $this->ruleSets = new RuleSetRepository($this->db, $this->clock);
        $this->ruleSets->save(RuleSet::defaults(), 'Neutral defaults', 0);
        $this->events = new EditorEventRepository($this->db);
        $this->operationEvents = new OperationEventRepository($this->db);
        $this->approvals = new class () implements PendingApprovals {
            /** @var array<string, int> */
            public array $waiting = [];

            /** @var list<array{string, string}> */
            public array $closed = [];

            public function waitingNights(string $propertyId): int
            {
                return $this->waiting[$propertyId] ?? 0;
            }

            public function expireAll(string $propertyId, string $reason): int
            {
                $this->closed[] = [$propertyId, $reason];
                $had = ($this->waiting[$propertyId] ?? 0) > 0 ? 1 : 0;
                $this->waiting[$propertyId] = 0;

                return $had;
            }
        };
        $this->editor = new RuleSetEditor($this->db, $this->clock, $this->ruleSets, $properties, $this->events, new RuleChecks($properties, $rooms), $this->approvals);
        $this->history = new VersionHistory($this->ruleSets, $this->events, $properties);
        $ledger = new OrderLedgerRepository($this->db);
        $this->freezes = new DetectedFreezeRepository($this->db);
        $this->orders = new OrderDesk($this->db, $this->clock, $properties, $rooms, $this->ruleSets, new OrderRepository($this->db, $this->clock), $ledger, $this->freezes, $this->events, $this->operationEvents);
        $this->board = new OrderBoard($this->clock, $ledger, $this->freezes);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->ruleSets->current()?->toArray() ?? [];
    }

    public function hash(): string
    {
        return (string) $this->ruleSets->current()?->hash();
    }
}
