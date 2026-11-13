<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules;

use RuntimeException;

/**
 * Saving rules (or an order) refused before anything was stored, with a reason code the screen
 * turns into a sentence and its plain parameters. Validation errors of the rules themselves are
 * an InvalidConfig, not this.
 */
final class RulesRefused extends RuntimeException
{
    /** A safety field changed without the capability ritmo_manage_safety. */
    public const SAFETY_NOT_ALLOWED = 'safety_not_allowed';

    /** The typed confirmation does not match; params: expected. */
    public const CONFIRMATION = 'confirmation';

    /** Someone saved the same values since the draft started; params: conflicts (paths). */
    public const CONFLICT = 'conflict';

    /** The note looks like guest data or is too long; params: problem. */
    public const NOTE = 'note';

    /** The draft is gone (expired, saved or discarded elsewhere). */
    public const NO_DRAFT = 'no_draft';

    /** The version asked for does not exist or does not read. */
    public const UNKNOWN_VERSION = 'unknown_version';

    /** A release of an order needs ritmo_manage_safety. */
    public const RELEASE_NOT_ALLOWED = 'release_not_allowed';

    /** The order or the freeze does not exist or is not active. */
    public const UNKNOWN_ORDER = 'unknown_order';

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct(sprintf('Rules change refused: %s.', $reason));
    }
}
