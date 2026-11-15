<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * What a draft is checked against: the property's today and the last night an order may cover,
 * its rooms with their base price, the person's own words (amounts and percentages of a draft
 * must be among them) and the rules in force.
 */
final class DraftContext
{
    public const HORIZON_DAYS = 365;

    /**
     * @param array<string, float>  $roomBases room code => listing base price
     * @param array<string, mixed>  $rules     the rules in force (RuleSet::toArray())
     * @param array<string, list<string>> $names field key => the names the person may use for the
     *                                           setting (its label in the language of the screen)
     * @param array<string, string> $roomLabels room code => its name, to tell the rooms the request names
     */
    public function __construct(
        public readonly DateTimeImmutable $today,
        public readonly array $roomBases,
        public readonly string $request,
        public readonly array $rules,
        public readonly array $names = [],
        public readonly array $roomLabels = [],
    ) {
    }

    public function lastNight(): DateTimeImmutable
    {
        return Days::add($this->today, self::HORIZON_DAYS);
    }
}
