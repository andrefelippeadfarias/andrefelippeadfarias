<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

use Ritmo\Contracts\Exception\WriteBlocked;

/**
 * The live registry of the write tokens: who says whether a token still allows a request.
 * The write gate implements it; the connectors ask it before every write, and the HTTP guard asks
 * it before any write route leaves the site.
 */
interface WriteAuthority
{
    /**
     * True while the token was issued by the gate of this process and is not revoked, expired,
     * stopped by the emergency switch or past the property's midnight.
     */
    public function isLive(WriteToken $token): bool;

    /**
     * Uses up one step of the token for the request: the token must be live and the request
     * inside one of the steps it was issued for (same operation, targets, nights and fields
     * within the step) that was not used yet.
     *
     * @throws WriteBlocked when the token does not allow the request
     */
    public function verify(WriteToken $token, WriteRequest $request): void;
}
