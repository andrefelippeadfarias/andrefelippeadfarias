<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Schedule;

/**
 * Plain-text e-mail through wp_mail().
 */
final class WpMailer implements Mailer
{
    public function send(array $to, string $subject, string $body): bool
    {
        if ($to === []) {
            return false;
        }

        return (bool) wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
    }
}
