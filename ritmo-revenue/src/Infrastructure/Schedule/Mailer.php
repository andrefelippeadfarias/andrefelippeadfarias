<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Schedule;

/**
 * Sends a plain-text e-mail.
 */
interface Mailer
{
    /**
     * @param list<string> $to
     *
     * @return bool false when the message could not be handed to the mail system
     */
    public function send(array $to, string $subject, string $body): bool;
}
