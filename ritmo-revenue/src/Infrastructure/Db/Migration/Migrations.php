<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Migration;

/**
 * The plugin's migrations, in order. A new migration is added at the end.
 */
final class Migrations
{
    /**
     * @return list<Migration>
     */
    public static function all(): array
    {
        return [
            new Migration0001Configuration(),
            new Migration0002Market(),
            new Migration0003Operation(),
            new Migration0004Housekeeping(),
            new Migration0005RunStages(),
            new Migration0006RunPayloads(),
            new Migration0007WritePath(),
            new Migration0008Confirmation(),
            new Migration0009Operations(),
        ];
    }
}
