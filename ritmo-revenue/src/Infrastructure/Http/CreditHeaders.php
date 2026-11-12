<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http;

use InvalidArgumentException;

/**
 * The response headers through which an API reports its call credit: how much is left in the
 * current window, in how many seconds the window resets and what the request cost. Each is a
 * list, because some APIs document one spelling and send another.
 */
final class CreditHeaders
{
    /**
     * @param list<string> $remaining
     * @param list<string> $resetsIn
     * @param list<string> $cost
     */
    public function __construct(
        public readonly array $remaining,
        public readonly array $resetsIn,
        public readonly array $cost = [],
    ) {
        if ($remaining === [] || $resetsIn === []) {
            throw new InvalidArgumentException('Credit headers need a name for the remaining credit and for the reset time.');
        }
    }

    /**
     * The headers of an API with a five-minute credit window, in both spellings seen in use.
     */
    public static function fiveMinuteWindow(): self
    {
        return new self(
            ['X-Five-Min-Limit-Remaining', 'X-FiveMinCreditLimit-Remaining'],
            ['X-Five-Min-Limit-Resets-In', 'X-FiveMinCreditLimit-ResetsIn'],
            ['X-Request-Cost', 'X-RequestCost']
        );
    }
}
