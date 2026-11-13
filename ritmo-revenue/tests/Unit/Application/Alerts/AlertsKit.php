<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Alerts;

use Ritmo\Application\Alerts\AlertNotifier;
use Ritmo\Application\Alerts\AlertService;
use Ritmo\Domain\Alerts\NotificationPolicy;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Schedule\AlertLedger;
use Ritmo\Infrastructure\Schedule\RecipientDirectory;
use Ritmo\Tests\Fakes\RecordingLogger;

/**
 * The alert service of the operations tests, with a recording mailer and logger.
 */
final class AlertsKit
{
    public readonly AlertLedger $ledger;

    public readonly RecordingMailer $mailer;

    public readonly RecordingLogger $logger;

    public readonly AlertNotifier $notifier;

    public readonly AlertService $service;

    /**
     * @param list<string> $recipients
     */
    public function __construct(Database $db, Clock $clock, array $recipients = ['owner@example.org'], int $maxPerHour = 6)
    {
        $this->ledger = new AlertLedger($db);
        $this->mailer = new RecordingMailer();
        $this->logger = new RecordingLogger();
        $directory = new class ($recipients) implements RecipientDirectory {
            /**
             * @param list<string> $addresses
             */
            public function __construct(private readonly array $addresses)
            {
            }

            public function addresses(): array
            {
                return $this->addresses;
            }
        };
        $this->notifier = new AlertNotifier($this->ledger, $this->mailer, $directory, new NotificationPolicy($maxPerHour, 3), $clock, $this->logger);
        $this->service = new AlertService($this->ledger, $this->notifier, $clock);
    }

    /**
     * Rows of the alerts table.
     */
    public static function rows(Database $db): int
    {
        return (int) $db->selectValue('SELECT COUNT(*) FROM ' . $db->table('alerts'));
    }
}
