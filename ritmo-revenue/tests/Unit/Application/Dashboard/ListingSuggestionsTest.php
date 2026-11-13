<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Dashboard;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Dashboard\Setup\ListingSuggestions;
use Ritmo\Domain\Model\ListingInfo;
use Ritmo\Domain\Model\SyncStatus;
use Ritmo\Infrastructure\Store\ArrayStore;

final class ListingSuggestionsTest extends TestCase
{
    public function testTheListingsBecomeRoomRowsWithUniqueCodesAndAGuessedPmsId(): void
    {
        $suggestions = new ListingSuggestions(new ArrayStore());
        $suggestions->remember([
            self::listing('901001___902001', 'Standard Room', 320.0, 480.0, 1600.0),
            self::listing('901001___902002', 'Garden Suite', 450.0, 700.0, 2400.0),
            self::listing('external-listing', 'Garden Studio', 410.5, null, 990.0, 'USD'),
        ]);

        $rows = $suggestions->rows(['SR']);

        self::assertSame(['SR2', 'GS', 'GS2'], array_column($rows, 'code'), 'initials, unique among the codes in use');
        self::assertSame('902001', $rows[0]['pms_ref'], 'the number at the end of the listing id');
        self::assertSame('', $rows[2]['pms_ref'], 'no guess without a number at the end');
        self::assertSame('901001___902002', $rows[1]['pricing_ref']);
        self::assertSame(['320', '480', '1600'], [$rows[0]['min'], $rows[0]['base'], $rows[0]['max']]);
        self::assertSame(['410.50', ''], [$rows[2]['min'], $rows[2]['base']]);
        self::assertSame('1', $rows[0]['units'], 'the units are not known from the pricing system');
        self::assertSame('BRL', $suggestions->currency(), 'the most frequent currency');
    }

    public function testNothingRememberedSuggestsNothing(): void
    {
        $store = new ArrayStore();
        $store->set(ListingSuggestions::KEY, [['ref' => ''], 'junk', ['name' => 'no ref']]);
        $suggestions = new ListingSuggestions($store);

        self::assertSame([], $suggestions->rows());
        self::assertNull($suggestions->currency());
    }

    private static function listing(string $ref, string $name, ?float $min, ?float $base, ?float $max, string $currency = 'BRL'): ListingInfo
    {
        return new ListingInfo($ref, $name, $min, $base, $max, $currency, new SyncStatus(true));
    }
}
