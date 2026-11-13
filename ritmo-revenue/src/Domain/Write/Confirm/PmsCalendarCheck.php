<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Confirm;

use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\NightFields;

/**
 * The re-read of a block of PMS calendar writes, before the next block is sent: every field of
 * every night of every range against the calendar read back. A night missing from the re-read
 * differs on every field sent.
 *
 * Reference: beds24._conferir.
 */
final class PmsCalendarCheck
{
    private function __construct()
    {
    }

    /**
     * @param list<PmsWrite>                            $writes
     * @param array<string, array<string, NightFields>> $reread room => "YYYY-MM-DD" => the night read back
     */
    public static function compare(array $writes, array $reread): PmsCheckResult
    {
        $differences = [];
        $writesWithError = 0;
        foreach ($writes as $write) {
            $before = count($differences);
            foreach ($write->nights() as $night) {
                $key = Days::key($night);
                $observed = $reread[$write->roomCode][$key] ?? NightFields::none();
                foreach (StoredCheck::pms($write->fields, $observed, $write->expectedAvailable) as $field) {
                    $differences[] = sprintf('%s %s: %s', $write->roomCode, $key, $field);
                }
            }
            $writesWithError += count($differences) > $before ? 1 : 0;
        }

        return new PmsCheckResult($differences, $writesWithError);
    }
}
