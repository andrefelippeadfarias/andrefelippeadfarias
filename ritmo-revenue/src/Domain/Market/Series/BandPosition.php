<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

/**
 * Where an amount sits among the market bands: near one band, between two, below the lowest or
 * above the highest.
 */
final class BandPosition
{
    public const NEAR = 'near';

    public const BETWEEN = 'between';

    public const BELOW = 'below';

    public const ABOVE = 'above';

    /** The amount matches no rule (two bands with the same value, say). */
    public const NONE = 'none';

    /**
     * @param list<string> $bands the band labels the position names (one, or two for between)
     */
    public function __construct(public readonly string $kind, public readonly array $bands)
    {
    }
}
