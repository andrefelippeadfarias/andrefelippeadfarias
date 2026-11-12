<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing\Protection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Pricing\Protection\ProtectionConfig;
use Ritmo\Domain\Pricing\Protection\ProtectionOutcome;
use Ritmo\Domain\Pricing\Protection\SoldOutProtection;
use Ritmo\Tests\Support\Golden;
use Ritmo\Tests\Unit\Domain\Pricing\Ladder\Reference;

/**
 * Parity of the sold-out protection with pace.protecao_esgotada (with the fix of 12 Nov: a higher
 * live floor stays when the ceiling is released).
 */
final class SoldOutProtectionGoldenTest extends TestCase
{
    #[DataProvider('cases')]
    public function testProtection(array $in, ?array $expected): void
    {
        $protection = new SoldOutProtection(ProtectionConfig::fromArray(Reference::parity()['soldout']), $in['tolerance']);
        $outcome = $protection->propose(
            Reference::room($in['room']),
            Reference::priceNight($in['room'], $in['calendar_row']),
            Reference::override($in['night'], $in['live_rule']),
            self::anchor($in['night'], $in['anchor']),
            $in['public_factor'],
            $in['standard_floor'],
            $in['extra_floor'] === null ? null : (float) $in['extra_floor'],
            $in['relation_ceiling'] === null ? null : (float) $in['relation_ceiling'],
        );
        if ($expected === null) {
            self::assertNull($outcome->item);
            self::assertContains($outcome->code, [ProtectionOutcome::ALREADY_IN_PLACE, ProtectionOutcome::UNKNOWN_LIVE_FLOOR]);

            return;
        }
        $item = $outcome->item;
        self::assertNotNull($item);
        self::assertSame(ProtectionOutcome::ITEM, $outcome->code);
        self::assertEqualsWithDelta($expected['min_price'], $item->minPrice, Golden::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta($expected['max_price'], $item->maxPrice, Golden::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta($expected['preco_antes'], $item->priceBefore, Golden::AMOUNT_TOLERANCE);
        self::assertSame($expected['floor_source'], $item->floorSource->value);
        self::assertSame($in['sold'], $item->sold);
        self::assertSame($in['units'], $item->units);
    }

    public static function cases(): array
    {
        return Reference::provider('pace.protecao_esgotada');
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function anchor(string $night, array $a): MarketAnchor
    {
        return new MarketAnchor(Reference::date($night), $a['p25'], $a['p50'], $a['p75'], $a['p90'], $a['max'], $a['n'], Reference::date($a['busca_em']), AnchorOrigin::Measured);
    }
}
