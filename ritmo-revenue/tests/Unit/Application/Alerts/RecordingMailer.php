<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Alerts;

use Ritmo\Infrastructure\Schedule\Mailer;

/**
 * Records the e-mails; can be told to fail or to throw.
 */
final class RecordingMailer implements Mailer
{
    /** @var list<array{list<string>, string, string}> */
    public array $sent = [];

    public bool $works = true;

    public bool $throws = false;

    public function send(array $to, string $subject, string $body): bool
    {
        if ($this->throws) {
            throw new \RuntimeException('The mail server is down.');
        }
        $this->sent[] = [$to, $subject, $body];

        return $this->works;
    }
}
