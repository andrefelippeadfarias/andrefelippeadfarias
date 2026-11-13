<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

use InvalidArgumentException;

/**
 * What the market series need to know about one competitor (a business the property compares
 * itself with, never a guest): its code, tags, comparable factor and whether it counts.
 */
final class CompetitorProfile
{
    /** A competitor code: lowercase letters, digits and dashes. */
    public const CODE_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    /** A tag: a slug the series filter on ("peer"). */
    public const TAG_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/';

    /**
     * @param list<string> $tags
     * @param float|null   $comparableFactor > 0 and <= 10; null = not set (the default factor applies)
     * @param string       $factorSource     ComparableFactor::MEASURED, ::ESTIMATED or ''
     * @param bool         $isOwn            the property's own listing: never part of a series
     *
     * @throws InvalidArgumentException on a malformed code, tag or factor
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly array $tags = [],
        public readonly ?float $comparableFactor = null,
        public readonly string $factorSource = '',
        public readonly bool $active = true,
        public readonly bool $isOwn = false,
    ) {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new InvalidArgumentException('A competitor code has lowercase letters, digits and dashes.');
        }
        foreach ($tags as $tag) {
            if (!is_string($tag) || preg_match(self::TAG_PATTERN, $tag) !== 1) {
                throw new InvalidArgumentException('A competitor tag is a short lowercase word.');
            }
        }
        if ($comparableFactor !== null && (!is_finite($comparableFactor) || $comparableFactor < 0 || $comparableFactor > 10)) {
            throw new InvalidArgumentException('A comparable factor is above 0 and at most 10.');
        }
        if (!in_array($factorSource, [ComparableFactor::MEASURED, ComparableFactor::ESTIMATED, ''], true)) {
            throw new InvalidArgumentException('Unknown source of a comparable factor.');
        }
    }

    /**
     * True when the competitor may take part in a series at all (active and not the property).
     */
    public function counts(): bool
    {
        return $this->active && !$this->isOwn;
    }

    public function hasAnyTag(array $tags): bool
    {
        return $tags === [] || array_intersect($tags, $this->tags) !== [];
    }
}
