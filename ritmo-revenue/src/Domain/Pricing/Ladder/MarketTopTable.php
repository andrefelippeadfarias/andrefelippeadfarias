<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeInterface;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * The public market top of the proportional ladder: the average of the highest comparable prices,
 * measured for holiday periods (by night) and for reference nights (by weekday or weekend).
 *
 * Reference: escada_reservas.topo_publico (dados/concorrentes_hidro_vista.json).
 */
final class MarketTopTable
{
    /**
     * @param list<array{label: string, nights: list<string>, top: ?float}>             $periods
     * @param list<array{label: string, night: string, weekend: bool, top: ?float}> $references
     * @param list<int>                                                                $weekendDays Monday = 0
     */
    public function __construct(
        private readonly array $periods,
        private readonly array $references,
        private readonly array $weekendDays,
    ) {
        foreach ($references as $reference) {
            if (!Days::isValid($reference['night'])) {
                throw new InvalidArgumentException('A market top reference needs a valid night.');
            }
        }
    }

    /**
     * The top of the holiday period that lists the night; otherwise the nearest reference of the
     * same kind (weekday or weekend; the first listed on a tie); null when none has a top.
     *
     * @return array{top: float, label: string}|null
     */
    public function topFor(DateTimeInterface $night): ?array
    {
        $key = Days::key($night);
        foreach ($this->periods as $period) {
            if ($period['top'] !== null && $period['top'] != 0.0 && in_array($key, $period['nights'], true)) {
                return ['top' => $period['top'], 'label' => $period['label']];
            }
        }
        $weekend = in_array(Days::weekday($night), $this->weekendDays, true);
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($this->references as $reference) {
            if ($reference['weekend'] !== $weekend || $reference['top'] === null || $reference['top'] == 0.0) {
                continue;
            }
            $distance = abs(Days::diff(Days::date($reference['night']), $night));
            if ($distance < $bestDistance) {
                $best = $reference;
                $bestDistance = $distance;
            }
        }

        return $best === null ? null : ['top' => $best['top'], 'label' => $best['label']];
    }
}
