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

    public function abandonTransaction(): bool
    {
        if ($this->transactionDepth === 0) {
            return false;
        }
        $this->transactionDepth = 0;
        try {
            $this->rollBackTransaction();
        } catch (Throwable) {
            // The connection may be gone; nothing was committed then either.
        }

        return true;
    }

    private function joinTransaction(callable $work): mixed
    {
        ++$this->transactionDepth;
        try {
            return $work();
        } finally {
            // abandonTransaction() may have closed it already.
            $this->transactionDepth = max(0, $this->transactionDepth - 1);
        }
    }
}
