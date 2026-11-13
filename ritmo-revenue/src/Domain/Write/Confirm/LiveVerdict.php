<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Confirm;

/**
 * The live check of one written night.
 */
final class LiveVerdict
{
    /** The stay differs on a free night between two sold-out ones: the pricing system's own orphan-day rule, never written again. */
    public const ISOLATED_NIGHT_STAY = 'isolated_night_stay';

    /**
     * @param bool         $priceChecked false on a sold-out night, where the price proves nothing
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly LiveStatus $status,
        public readonly bool $priceChecked = true,
        public readonly array $warnings = [],
    ) {
    }
}
