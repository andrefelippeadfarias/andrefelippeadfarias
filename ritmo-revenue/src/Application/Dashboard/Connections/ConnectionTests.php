<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Connections;

use DateTimeImmutable;
use Ritmo\Contracts\ConnectionStatus;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Domain\Support\Clock;

/**
 * The results of the connection tests (the settings ModePreconditions reads: ok and when) with
 * the reason and the counts the screen translates, and the cooldown of one test per provider
 * every 30 seconds (admin spec 5.13, decision D26).
 */
final class ConnectionTests
{
    public const PREFIX = 'connection_test_';

    public const COOLDOWN_SECONDS = 30;

    public function __construct(private readonly KeyValueStore $settings, private readonly Clock $clock)
    {
    }

    /**
     * @return array{ok: bool, reason: string, counts: array<string, int|string>, at: DateTimeImmutable, by: int}|null
     */
    public function last(string $provider): ?array
    {
        $stored = $this->settings->get(self::PREFIX . $provider);
        if (!is_array($stored) || !is_string($stored['at'] ?? null)) {
            return null;
        }
        $reason = is_string($stored['reason'] ?? null) && in_array($stored['reason'], ConnectionReason::ALL, true)
            ? $stored['reason']
            : (($stored['ok'] ?? false) === true ? ConnectionReason::OK : ConnectionReason::UNKNOWN);

        return [
            'ok' => ($stored['ok'] ?? false) === true,
            'reason' => $reason,
            'counts' => is_array($stored['counts'] ?? null) ? $stored['counts'] : [],
            'at' => new DateTimeImmutable($stored['at']),
            'by' => (int) ($stored['by'] ?? 0),
        ];
    }

    /**
     * The last result when it is younger than the cooldown: the caller answers it without testing.
     *
     * @return array{ok: bool, reason: string, counts: array<string, int|string>, at: DateTimeImmutable, by: int}|null
     */
    public function withinCooldown(string $provider): ?array
    {
        $last = $this->last($provider);

        return $last !== null && $this->clock->now()->getTimestamp() - $last['at']->getTimestamp() < self::COOLDOWN_SECONDS ? $last : null;
    }

    /**
     * @return array{ok: bool, reason: string, counts: array<string, int|string>, at: DateTimeImmutable, by: int}
     */
    public function record(string $provider, ConnectionStatus $status, int $userId): array
    {
        $at = $this->clock->now();
        $result = ['ok' => $status->ok, 'reason' => ConnectionReason::of($status), 'counts' => ConnectionReason::counts($status), 'at' => $at, 'by' => $userId];
        $this->settings->set(self::PREFIX . $provider, [
            'ok' => $status->ok,
            'message' => $status->message,
            'at' => $at->format(DATE_ATOM),
            'reason' => $result['reason'],
            'counts' => $result['counts'],
            'by' => $userId,
        ]);

        return $result;
    }
}
