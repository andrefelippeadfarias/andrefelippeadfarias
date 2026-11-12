<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db;

use Throwable;

/**
 * Database::transaction() for both implementations: only the outermost call opens and closes
 * the real transaction; inner calls join it, and a failure anywhere rolls the whole of it back.
 */
trait NestedTransactions
{
    private int $transactionDepth = 0;

    abstract private function beginTransaction(): void;

    abstract private function commitTransaction(): void;

    abstract private function rollBackTransaction(): void;

    public function transaction(callable $work): mixed
    {
        if ($this->transactionDepth > 0) {
            return $this->joinTransaction($work);
        }

        $this->beginTransaction();
        try {
            $result = $this->joinTransaction($work);
        } catch (Throwable $failure) {
            try {
                $this->rollBackTransaction();
            } catch (Throwable) {
                // The failure that caused the rollback is the one to report.
            }

            throw $failure;
        }
        $this->commitTransaction();

        return $result;
    }

    private function joinTransaction(callable $work): mixed
    {
        ++$this->transactionDepth;
        try {
            return $work();
        } finally {
            --$this->transactionDepth;
        }
    }
}
