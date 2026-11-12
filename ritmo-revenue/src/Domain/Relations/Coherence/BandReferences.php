<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;
use Ritmo\Domain\Relations\MemberPolicy;
use Ritmo\Domain\Relations\Params\BandParams;
use Ritmo\Domain\Relations\Params\EmptySetPolicy;
use Ritmo\Domain\Relations\Relation;

/**
 * The prices a discount band compares with: its members that are not closed in the PMS and have a
 * price, the sold-out ones only when the band says so.
 */
final class BandReferences
{
    /**
     * The members a band uses tonight.
     *
     * @return list<float>
     */
    public static function forBand(CoherenceContext $context, Relation $band, BandParams $params, DateTimeImmutable $night): array
    {
        $refs = self::collect($context, $band, $night, $params->members === MemberPolicy::FreeOnly);
        if ($refs === [] && $params->onEmptySet === EmptySetPolicy::UseSoldOut) {
            $refs = self::collect($context, $band, $night, false);
        }

        return $refs;
    }

    /**
     * The free members only (the cap of an occupancy switch).
     *
     * @return list<float>
     */
    public static function free(CoherenceContext $context, Relation $band, DateTimeImmutable $night): array
    {
        return self::collect($context, $band, $night, true);
    }

    /**
     * @return list<float>
     */
    private static function collect(CoherenceContext $context, Relation $band, DateTimeImmutable $night, bool $freeOnly): array
    {
        $facts = $context->facts;
        $refs = [];
        foreach ($context->graph->members($band) as $room) {
            $usable = $freeOnly ? $facts->isFreeUnblocked($room, $night) : !$facts->isBlocked($room, $night);
            $price = $facts->price($room, $night);
            if ($usable && $price !== null) {
                $refs[] = $price;
            }
        }

        return $refs;
    }
}
