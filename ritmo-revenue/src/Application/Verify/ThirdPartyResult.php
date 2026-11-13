<?php

declare(strict_types=1);

namespace Ritmo\Application\Verify;

use Ritmo\Domain\Orders\OwnerOrder;
use Ritmo\Domain\Write\ThirdParty\DetectedFreeze;
use Ritmo\Domain\Write\ThirdParty\ThirdPartyChange;

/**
 * What a run found changed by others, the freezes it opened and every manual order the active
 * freezes give the run's order book.
 */
final class ThirdPartyResult
{
    /**
     * @param list<ThirdPartyChange> $changes
     * @param list<DetectedFreeze>   $freezes opened in this run
     * @param list<OwnerOrder>       $orders  of every active freeze of the property
     */
    public function __construct(
        public readonly array $changes,
        public readonly array $freezes,
        public readonly array $orders,
    ) {
    }
}
