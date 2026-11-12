<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing\Cadence;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\Cadence\NoEffectFilter;
use Ritmo\Tests\Unit\Domain\Pricing\Ladder\Reference;

/**
 * Parity of the no-effect filter with pace.sem_efeito (rounding-only drop off, as in the parity preset).
 */
final class NoEffectFilterGoldenTest extends TestCase
{
    #[DataProvider('cases')]
    public function testNoEffect(array $in, array $expected): void
    {
        $it = $in['item'];
        $item = new PlanItem($it['quarto'], Reference::date($it['data']), (float) $it['min_price'], isset($it['max_price']) ? (float) $it['max_price'] : null, $it['min_stay'] ?? null);
        $key = $item->key();
        $live = $in['calendar_row'] === null ? [] : [$key => Reference::priceNight($it['quarto'], $in['calendar_row'])];
        $overrides = $in['live_rule'] === null ? [] : [$key => Reference::override($it['data'], $in['live_rule'])];
        $delete = [];
        foreach ($in['delete_first'] as [$room, $night]) {
            $delete[PlanItem::keyOf($room, Reference::date($night))] = true;
        }
        $result = (new NoEffectFilter(false))->filter([$item], Reference::rooms(), $live, $overrides, $delete);

        self::assertSame($expected['kept'], $result->kept !== []);
        self::assertSame($expected['dropped_as_no_effect'], $result->noEffect !== []);
        self::assertSame([], $result->roundingOnly);
    }

    public static function cases(): array
    {
        return Reference::provider('pace.sem_efeito');
    }
}
