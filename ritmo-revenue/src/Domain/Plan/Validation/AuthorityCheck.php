<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

use DateTimeImmutable;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Orders\OwnerOrder;
use Ritmo\Domain\Plan\IssueSubject;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Plan\Port\StrategyGuard;

/**
 * The owner order an entry cites: a night frozen by a manual change of the owner (other than the
 * cited order) is never touched; neither is a night whose price an owner order fixed, unless the
 * entry cites that very order; a cited order must be in force for the room and night and, when it
 * backs a strategy, the strategy must act there. Items and deletions are checked alike (a listing
 * change is checked on today only for the freeze: a fixed price is an amount, not a percentage).
 *
 * Reference: validar_plano.Validador.validar_data (excecao "dono" with ordem_id, congelada,
 * escopo_plano). The fixed-price night is a plugin rule (divergence X1 of docs/VERIFY.md): the
 * reference engine only stays away from it, its validator does not look.
 */
final class AuthorityCheck
{
    /** An owner order fixed the price of the night and the entry does not cite it. */
    public const NIGHT_FIXED_BY_ORDER = 'night_fixed_by_order';

    public function __construct(
        private readonly OrderBook $orders,
        private readonly StrategyGuard $strategies,
        private readonly IssueLog $log,
    ) {
    }

    public function resolve(int $index, string $room, DateTimeImmutable $night, ?string $orderId, IssueSubject $subject = IssueSubject::Item): ItemAuthority
    {
        $frozen = $this->orders->manualFreeze($room, $night);
        if ($frozen !== null && $frozen->id !== $orderId) {
            $this->log->add(true, PlanIssue::NIGHT_FROZEN, sprintf('The owner changed this night by hand (order %s): nothing else may touch it.', $frozen->id), $index, $room, $night, ['order_id' => $frozen->id], $subject);
        }
        $fixed = $subject === IssueSubject::Listing ? [] : array_map(static fn (OwnerOrder $o): string => $o->id, $this->orders->fixedPrice($room, $night));
        if ($fixed !== [] && !in_array($orderId, $fixed, true)) {
            $this->log->add(true, self::NIGHT_FIXED_BY_ORDER, sprintf('The owner fixed the price of this night (order %s): only an entry citing that order may touch it.', implode(', ', $fixed)), $index, $room, $night, ['order_ids' => $fixed], $subject);
        }
        if ($orderId === null) {
            return new ItemAuthority();
        }
        $order = $this->orders->inForce($orderId, $room, $night);
        if ($order === null) {
            $this->log->add(true, PlanIssue::ORDER_NOT_IN_FORCE, sprintf('Order %s is not in force for this room and night.', $orderId), $index, $room, $night, ['order_id' => $orderId], $subject);

            return new ItemAuthority();
        }
        $strategy = $this->strategies->backsStrategy($orderId);
        if ($strategy && !$this->strategies->actsOn($orderId, $room, $night)) {
            $this->log->add(true, PlanIssue::ORDER_OUT_OF_SCOPE, sprintf('Order %s backs a strategy that does not act on this room and night.', $orderId), $index, $room, $night, ['order_id' => $orderId], $subject);

            return new ItemAuthority();
        }

        return new ItemAuthority($order, $strategy);
    }
}
