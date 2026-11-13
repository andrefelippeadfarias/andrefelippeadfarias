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

    /**
     * The HTTP guard asks before a write leaves: true only for a live token whose connector has
     * just used a step for it (verify()); the same request may be claimed once more (a token
     * renewed after a 401). A live token alone never lets a write through.
     */
    public function claim(WriteToken $token, string $method, string $url, ?string $body): bool;
}
