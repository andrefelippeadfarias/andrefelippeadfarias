<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

use InvalidArgumentException;

/**
 * One series of the market ruler: a name the ladder rules cite, the room basis it measures and
 * the competitors it takes (every competitor, or those carrying any of its tags).
 */
final class SeriesDefinition
{
    /** The name of the M1 anchor series; never a configured series. */
    public const ANCHOR = 'market';

    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /**
     * @param list<string> $tags empty = every competitor
     *
     * @throws InvalidArgumentException on a malformed name or tag
     */
    public function __construct(
        public readonly string $name,
        public readonly RoomBasis $basis,
        public readonly array $tags = [],
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || $name === self::ANCHOR) {
            throw new InvalidArgumentException(sprintf('Invalid series name "%s".', $name));
        }
        foreach ($tags as $tag) {
            if (!is_string($tag) || preg_match(CompetitorProfile::TAG_PATTERN, $tag) !== 1) {
                throw new InvalidArgumentException('A series tag is a short lowercase word.');
            }
        }
    }

    public function takes(CompetitorProfile $competitor): bool
    {
        return $competitor->counts() && $competitor->hasAnyTag($this->tags);
    }
}
