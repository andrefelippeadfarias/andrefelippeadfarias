<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Relations\Coherence\NightFacts;
use Ritmo\Domain\Relations\Params\PremiumParams;
use Ritmo\Domain\Relations\Port\NightCalendar;
use Ritmo\Domain\Relations\Port\PriceBandSource;
use Ritmo\Domain\Relations\Port\SentBand;

/**
 * The floor and ceiling of the target of a premium relation on a night, BEFORE the coherence
 * stage: the pace step and the sold-out protection of that room ask for them.
 *
 * Floor: the dearest member that is not sold out (its decided price in this run, else its
 * calendar price) times one plus the premium of the night type; 0 without such a member.
 * With a price band tonight (up to the band's last night), the floor is at least the band's low
 * end and the ceiling is its high end. No epsilon and no tolerance here.
 */
final class PremiumBounds
{
    public function __construct(
        public readonly float $floor,
        public readonly ?float $ceiling,
    ) {
    }

    /**
     * @param list<string> $members the resolved set of the relation
     */
    public static function forNight(
        Relation $relation,
        array $members,
        DateTimeImmutable $night,
        NightFacts $facts,
        NightCalendar $calendar,
        PriceBandSource $bands,
        DateTimeImmutable $today,
    ): self {
        $params = $relation->params;
        if (!$params instanceof PremiumParams) {
            throw new InvalidArgumentException('Premium bounds need a premium_over_max relation.');
        }
        $others = [];
        foreach ($members as $room) {
            $row = $facts->row($room, $night);
            if ($row !== null && !$row->isSoldOut()) {
                $others[] = $facts->decided($room, $night) ?? $row->price;
            }
        }
        $floor = $others === [] ? 0.0 : max($others) * (1 + $params->premiumFor($calendar->nightType($night)));
        $band = self::bandOf($bands, $relation->target, $night, $today);
        if ($band === null) {
            return new self($floor, null);
        }

        return new self(max($floor, $band->low), $band->high);
    }

    /**
     * The price band of a room tonight, when the night is not beyond the band's last night.
     */
    public static function bandOf(PriceBandSource $bands, string $room, DateTimeImmutable $night, DateTimeImmutable $today): ?SentBand
    {
        $last = $bands->lastNight($room, $today);
        if ($last === null || $night > $last) {
            return null;
        }

        return $bands->sentBand($room, $night);
    }
}
