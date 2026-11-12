<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Beds24;

use InvalidArgumentException;
use LogicException;

/**
 * The credential of a Beds24 account: either a long life token, sent as it is, or a refresh
 * token, exchanged for short-lived access tokens.
 *
 * The secret never appears in a dump of the object.
 */
final class Beds24Credentials
{
    private function __construct(
        private readonly string $secret,
        private readonly bool $refreshable,
    ) {
        if ($secret === '' || preg_match('/[\x00-\x20\x7f]/', $secret) === 1) {
            throw new InvalidArgumentException('A Beds24 token cannot be empty or contain spaces or control characters.');
        }
    }

    /**
     * A long life token: it is the access token and cannot be renewed.
     */
    public static function longLifeToken(string $token): self
    {
        return new self($token, false);
    }

    /**
     * A refresh token: access tokens are obtained from it and renewed when rejected.
     */
    public static function refreshToken(string $token): self
    {
        return new self($token, true);
    }

    public function isRefreshable(): bool
    {
        return $this->refreshable;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    /**
     * @return array{kind: string, secret: string}
     */
    public function __debugInfo(): array
    {
        return ['kind' => $this->refreshable ? 'refresh token' : 'long life token', 'secret' => '(hidden)'];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LogicException always: a credential is never written to a cache, a session or a log
     */
    public function __serialize(): array
    {
        throw new LogicException('Beds24 credentials cannot be serialised.');
    }
}
