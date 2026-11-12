<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Port;

use DateTimeImmutable;

/**
 * What the validator asks the pricing strategies (the sales ladder) about the owner orders behind
 * them. An order that backs a strategy authorises an item only where its strategy acts, and its
 * last-minute market floor is how far it may take a night under the listing minimum and the owner
 * floors.
 *
 * Reference: validar_plano.Validador.escopo_plano, piso_mercado_do_plano and the weekend floor of
 * padrao_qualidade (plano_dono.ativo).
 */
interface StrategyGuard
{
    /** True when the order backs a strategy (and so authorises only inside its scope). */
    public function backsStrategy(string $orderId): bool;

    /** True when the strategy of the order acts on the room night (governs it, sets its market floor, or switches it). */
    public function actsOn(string $orderId, string $roomCode, DateTimeImmutable $night): bool;

    /** The last-minute market floor of the order's strategy on the room night, sent plan; null outside its window. */
    public function marketFloor(string $orderId, string $roomCode, DateTimeImmutable $night): ?float;

    /** True when an active strategy replaces the owner floors of the night with the quality floor. */
    public function replacesOwnerFloors(string $roomCode, DateTimeImmutable $night): bool;
}
