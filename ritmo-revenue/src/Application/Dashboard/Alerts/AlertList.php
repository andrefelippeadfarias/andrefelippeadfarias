<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Alerts;

/**
 * The read port of the Alerts screen (implemented over the alerts table in
 * Infrastructure\Db\Query\Screens). Rows are plain values; the message stored in English is not
 * part of them: the screen writes its own sentence from the code and the facts.

 */
interface AlertList
{
    public function count(AlertFilter $filter): int;

    /**
     * @return list<array<string, mixed>> newest first
     */
    public function page(AlertFilter $filter, int $offset, int $limit): array;

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array;

    /**
     * Open alerts of these severities (the menu counter and the status strip).
     *
     * @param list<string> $severities
     */
    public function countBySeverity(?string $propertyId, array $severities): int;
}
