<?php

declare(strict_types=1);

namespace Ritmo\Application\Alerts;

use Ritmo\Contracts\Logger;
use Ritmo\Domain\Alerts\AlertRecord;
use Ritmo\Domain\Alerts\Delivery;
use Ritmo\Domain\Alerts\NotificationPolicy;
use Ritmo\Domain\Alerts\RaiseAction;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Schedule\AlertLedger;
use Ritmo\Infrastructure\Schedule\Mailer;
use Ritmo\Infrastructure\Schedule\RecipientDirectory;
use Throwable;

/**
 * Tells people about alerts: critical ones by e-mail at once (within the hourly cap per
 * property), the rest in the daily digest. A mail that cannot be sent is logged and left for the
 * digest; it never fails the run that raised the alert.
 */
final class AlertNotifier
{
    public function __construct(
        private readonly AlertLedger $ledger,
        private readonly Mailer $mailer,
        private readonly RecipientDirectory $recipients,
        private readonly NotificationPolicy $policy,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function notify(AlertRecord $alert, RaiseAction $action): Delivery
    {
        $now = $this->clock->now();
        $delivery = $this->policy->delivery($alert, $action, $this->ledger->notifiedSince($alert->propertyId, $now->modify('-1 hour')));
        if ($delivery === Delivery::Immediate && $this->send(MailText::subject($alert), MailText::body($alert), ['alert_id' => $alert->id])) {
            $this->ledger->markNotified($alert->id, $now);
        }

        return $delivery;
    }

    /**
     * Sends the digest of a property: the warnings, and the critical alerts the cap or a mail
     * failure kept back, not sent before.
     *
     * @return int alerts in the digest sent; 0 when there was nothing to send or it failed
     */
    public function sendDigest(string $propertyId): int
    {
        $pending = $this->ledger->pendingDigest($propertyId);
        if ($pending === []) {
            return 0;
        }
        if (!$this->send(MailText::digestSubject($propertyId, count($pending)), MailText::digestBody($pending), ['property_id' => $propertyId])) {
            return 0;
        }
        $this->ledger->markDigested(array_map(static fn (AlertRecord $a): int => $a->id, $pending), $this->clock->now());

        return count($pending);
    }

    /**
     * @param array<string, int|string> $context for the log
     */
    private function send(string $subject, string $body, array $context): bool
    {
        try {
            $to = $this->recipients->addresses();
            if ($to === []) {
                $this->logger->warning('An alert e-mail has no recipient: nobody can approve, or everyone opted out.', $context);

                return false;
            }
            if ($this->mailer->send($to, $subject, $body)) {
                return true;
            }
            $this->logger->warning('An alert e-mail could not be sent.', $context);
        } catch (Throwable $e) {
            $this->logger->warning('An alert e-mail could not be sent.', $context + ['exception' => $e::class]);
        }

        return false;
    }
}
