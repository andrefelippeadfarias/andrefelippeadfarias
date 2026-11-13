<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Gate;

use DateTimeImmutable;

/**
 * Who allows a plan to be written: the property's autonomous mode, or a person's approval of
 * exactly this plan. Checked by the gate when it issues the token, against the property as read
 * now.
 */
interface WriteAuthorization
{
    /**
     * Why this authorization does not allow the plan now; null when it does.
     *
     * @param string            $planHash PlanHash::of() of the plan asking for a token
     * @param DateTimeImmutable $today    the property's today by the clock now
     */
    public function refusal(WriteContext $context, string $planHash, DateTimeImmutable $today): ?string;

    /**
     * True when a person approved; listing changes and undo need it.
     */
    public function isHuman(): bool;

    /**
     * True when the authorization names this listing change ("room code|field").
     */
    public function allowsListingChange(string $roomCode, string $field): bool;

    /**
     * Who to record as the author of the changes ("autonomous" or the user id).
     */
    public function actor(): string;
}
