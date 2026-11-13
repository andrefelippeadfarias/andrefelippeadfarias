<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Schedule;

/**
 * Who receives the alert e-mails.
 */
interface RecipientDirectory
{
    /**
     * @return list<string> e-mail addresses, without repetitions
     */
    public function addresses(): array;
}
