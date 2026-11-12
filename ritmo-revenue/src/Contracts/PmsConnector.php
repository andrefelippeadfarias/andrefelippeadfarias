<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

use DateTimeImmutable;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\NotAvailableInThisMilestone;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\InventoryNight;

/**
 * A property management system: the source of truth for bookings and inventory.
 *
 * Dates are date-only values (midnight UTC, see Days). $from and $to are nights, BOTH INCLUSIVE.
 * Bookings never carry guest names or contacts: the connector drops them while mapping.
 */
interface PmsConnector
{
    /**
     * Stable id of the connector ("beds24").
     */
    public function id(): string;

    /**
     * Checks credentials and reachability with a cheap read. Never throws: failures come back
     * as a status with ok = false.
     */
    public function testConnection(): ConnectionStatus;

    /**
     * Bookings of every status with at least one night inside [$from, $to].
     *
     * @return list<BookingRecord>
     *
     * @throws ConnectorException
     */
    public function readBookingsByStay(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Bookings of every status created or modified at or after the instant.
     *
     * @param DateTimeImmutable $instant instant in UTC
     *
     * @return list<BookingRecord>
     *
     * @throws ConnectorException
     */
    public function readBookingsModifiedSince(DateTimeImmutable $instant): array;

    /**
     * The inventory calendar of the given rooms for the nights [$from, $to].
     *
     * @param list<string> $roomRefs   PMS room ids (RoomType::$pmsRef)
     * @param bool         $withPrices also read the price the PMS holds for each night
     *
     * @return array<string, list<InventoryNight>> PMS room id => nights in ascending order; nights the PMS
     *                                             does not report are absent
     *
     * @throws ConnectorException
     */
    public function readInventory(array $roomRefs, DateTimeImmutable $from, DateTimeImmutable $to, bool $withPrices = false): array;

    /**
     * Writes stay, arrival/departure and availability changes to the PMS calendar. Never prices.
     *
     * The shape of $changes is provisional: it is fixed together with the validated plan in the
     * milestone that enables writing.
     *
     * @param list<array<string, mixed>> $changes
     *
     * @throws NotAvailableInThisMilestone always, while the plugin is read-only
     * @throws ConnectorException
     */
    public function writeCalendar(WriteToken $token, array $changes): void;
}
