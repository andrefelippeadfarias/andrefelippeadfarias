<?php

declare(strict_types=1);

namespace Ritmo\Application\Alerts;

use Ritmo\Domain\Alerts\AlertRecord;
use Ritmo\Domain\Support\Days;

/**
 * The plain-text e-mails of the alerts: codes, property, rooms, nights, booking ids, channels and
 * amounts as the alert carries them. Never guest data: the alerts never hold any (GuestDataGuard).
 */
final class MailText
{
    private const PREFIX = '[Ritmo Revenue]';

    public static function subject(AlertRecord $alert): string
    {
        return sprintf('%s %s alert: %s%s', self::PREFIX, ucfirst($alert->severity->value), $alert->code, $alert->propertyId === '' ? '' : ' (' . $alert->propertyId . ')');
    }

    public static function body(AlertRecord $alert): string
    {
        return implode("\n", [...self::lines($alert), '', 'Open the Ritmo Revenue alerts screen to acknowledge or resolve it.']);
    }

    public static function digestSubject(string $propertyId, int $count): string
    {
        return sprintf('%s Daily summary of %d alert%s (%s)', self::PREFIX, $count, $count === 1 ? '' : 's', $propertyId);
    }

    /**
     * @param list<AlertRecord> $alerts
     */
    public static function digestBody(array $alerts): string
    {
        $blocks = [];
        foreach ($alerts as $alert) {
            $blocks[] = implode("\n", [sprintf('- %s (%s)', $alert->code, $alert->severity->value), ...array_map(static fn (string $l): string => '  ' . $l, self::lines($alert))]);
        }

        return implode("\n\n", [...$blocks, 'Open the Ritmo Revenue alerts screen for the details.']);
    }

    /**
     * @return list<string>
     */
    private static function lines(AlertRecord $alert): array
    {
        $lines = [$alert->message, 'Alert id: ' . $alert->id];
        if ($alert->roomCode !== '') {
            $lines[] = 'Room: ' . $alert->roomCode;
        }
        if ($alert->night !== null) {
            $lines[] = 'Night: ' . Days::key($alert->night);
        }
        foreach ($alert->context as $key => $value) {
            $lines[] = sprintf('%s: %s', str_replace('_', ' ', (string) $key), self::value($value));
        }

        return $lines;
    }

    private static function value(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(static fn (mixed $v, int|string $k): string => is_int($k) ? self::value($v) : $k . ' ' . self::value($v), $value, array_keys($value)));
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return $value === null ? '-' : (string) $value;
    }
}
