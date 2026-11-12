<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use InvalidArgumentException;
use Ritmo\Infrastructure\Db\Database;

/**
 * Finds a row by its user-chosen code the same way on every database.
 *
 * MySQL usually compares text without regard to case and SQLite with it, so "A1" and "a1"
 * would be one row on one site and two on another. The lookup is exact, and a code that
 * differs from an existing one only in case is refused.
 */
final class CodeLookup
{
    /**
     * Id of the row whose "code" column is exactly $code; null when there is none.
     *
     * @param string $table plugin table name without the prefix
     *
     * @throws InvalidArgumentException when another row has the same code in a different case
     */
    public static function id(Database $db, string $table, string $code): ?int
    {
        $rows = $db->select(
            sprintf('SELECT id, code FROM %s WHERE LOWER(code) = ?', $db->table($table)),
            [strtolower($code)]
        );
        foreach ($rows as $row) {
            if ($row['code'] !== $code) {
                throw new InvalidArgumentException(sprintf('The code "%s" is already used as "%s"; codes cannot differ only in case.', $code, (string) $row['code']));
            }
        }

        return $rows === [] ? null : (int) $rows[0]['id'];
    }
}
