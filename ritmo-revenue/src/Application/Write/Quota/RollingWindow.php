<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Quota;

use DateTimeImmutable;

/**
 * When a rolling window of attempts reopens.
 * Reference: cota_recalculo.abre_em.
 */
final class RollingWindow
{
    /**
     * The instant the count inside the window falls below the limit: the window after the attempt
     * that, when it leaves, leaves limit - 1 inside (with exactly the limit, the window after the
     * oldest). Null when the count is already below the limit.
     *
     * @param list<DateTimeImmutable> $instants
     */
    public static function opensAt(array $instants, int $limit, int $windowSeconds): ?DateTimeImmutable
    {
        if (count($instants) < $limit) {
            return null;
        }
        usort($instants, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);

        return $instants[count($instants) - $limit]->modify(sprintf('+%d seconds', $windowSeconds));
    }
}
