<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

/**
 * How the single PMS batch is cut: the nights from today to today + urgentDays go first, and the
 * writer sends at most maxRanges ranges per request.
 *
 * Reference: calendario_12m.lote_unico (dias_urgente) and beds24.MAX_FAIXAS_POST.
 */
final class BatchPolicy
{
    public function __construct(
        public readonly int $urgentDays = 14,
        public readonly int $maxRanges = 100,
    ) {
    }
}
