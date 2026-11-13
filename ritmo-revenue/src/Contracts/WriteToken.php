<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

use LogicException;

/**
 * Proof that a write to an external system was authorised by the write gate for one validated
 * plan. Every write method of a connector requires one.
 *
 * Only the write gate (Application\Write\WriteGate::issue()) creates a token, through the private
 * constructor, and it keeps every token it issued in a live registry with what the token may do.
 * The object itself carries nothing but an id for the journal: a token made some other way
 * (reflection without the constructor, a copy) is not in the registry, so the connectors and the
 * HTTP guard refuse it (WriteAuthority::verify(), WriteAuthority::isLive()). A token cannot be
 * cloned, serialised nor rebuilt from a serialised string.
 */
final class WriteToken
{
    /**
     * @param string $id random identifier, for the journal only (it grants nothing)
     */
    private function __construct(public readonly string $id)
    {
    }

    private function __clone()
    {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LogicException always: a token lives only in the process that issued it
     */
    public function __serialize(): array
    {
        throw new LogicException('A write token cannot be serialised.');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws LogicException always: only the write gate issues a token
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A write token cannot be unserialised: only the write gate issues one.');
    }
}
