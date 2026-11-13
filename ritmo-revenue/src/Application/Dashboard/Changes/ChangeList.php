<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Changes;

use Ritmo\Domain\Write\Change\ChangeRecord;

/**
 * The read port of the Change log (implemented over change_log in
 * Infrastructure\Db\Query\Screens): night and listing changes only, never the operation events.
 */
interface ChangeList
{
    public function count(ChangeFilter $filter): int;

    /**
     * @return list<ChangeRecord> newest first
     */
    public function page(ChangeFilter $filter, int $offset, int $limit): array;
}
