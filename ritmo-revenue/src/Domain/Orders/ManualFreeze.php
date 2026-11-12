<?php

declare(strict_types=1);

namespace Ritmo\Domain\Orders;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Support\Days;

/**
 * Records a change the owner made by hand in the pricing system: the nights become a manual
 * order that keeps every engine away from them for a few days (orders.manual_freeze_days).
 *
 * Only the order is built here; storing it is the caller's job.
 *
 * Reference: ordens.registrar_manual.
 */
final class ManualFreeze
{
    public function __construct(private readonly int $days = 7)
    {
        if ($days < 1) {
            throw new InvalidArgumentException('A manual change freezes a night for at least one day.');
        }
    }

    /**
     * The manual order for a change on $room from $from to $to made today. It ends on $to or
     * after the freeze days, whichever comes first. Recording the same room, first night and day
     * again gives the same id, so the new order replaces the old one ({@see OrderBook::with()}).
     *
     * @throws InvalidArgumentException on an invalid room code, or when the change starts after the
     *                                   freeze would end (it would freeze nothing)
     */
    public function record(string $room, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $today, string $note = ''): OwnerOrder
    {
        if (!Identifier::isCode($room)) {
            throw new InvalidArgumentException(sprintf('Invalid room type code "%s".', $room));
        }
        $end = min(Days::normalize($to), Days::add($today, $this->days));
        $id = sprintf('manual-%s-%s-%s', Days::key($today), strtolower($room), Days::key($from));

        return new OwnerOrder($id, OrderType::Manual, [$room], $from, $end, givenOn: $today, note: $note);
    }
}
