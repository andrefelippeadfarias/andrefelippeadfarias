<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\ThirdParty;

use DateTimeImmutable;
use Ritmo\Domain\Write\State\Provider;

/**
 * A room night someone other than the plugin changed since the last observation.
 */
final class ThirdPartyChange
{
    /**
     * @param array<string, array{0: int|float|string|null, 1: int|float|string|null}> $fields field => [before, after]
     */
    public function __construct(
        public readonly Provider $provider,
        public readonly string $roomCode,
        public readonly DateTimeImmutable $night,
        public readonly array $fields,
        public readonly DateTimeImmutable $previousAt,
    ) {
    }
}
