<?php

declare(strict_types=1);

namespace Ritmo\Connectors\PriceLabs;

use Ritmo\Connectors\Support\Payload;
use Ritmo\Domain\Model\ListingInfo;
use Ritmo\Domain\Model\SyncStatus;

/**
 * Turns an item of the PriceLabs listing directory into a ListingInfo.
 */
final class ListingMapper
{
    /**
     * @param array<int|string, mixed> $listing
     *
     * @return ListingInfo|null null for an item without an id
     */
    public function map(array $listing): ?ListingInfo
    {
        $id = Payload::text($listing, 'id', 128);
        if ($id === '') {
            return null;
        }

        $currency = strtoupper(Payload::text($listing, 'currency', 8));

        return new ListingInfo(
            $id,
            Payload::text($listing, 'name', 200),
            Payload::positive($listing, 'min'),
            Payload::positive($listing, 'base'),
            Payload::positive($listing, 'max'),
            preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null,
            new SyncStatus(
                Payload::flag($listing, 'push_enabled'),
                Payload::instant($listing, 'last_date_pushed'),
                Payload::instant($listing, 'last_refreshed_at')
            )
        );
    }

    /**
     * The PMS a listing belongs to, as PriceLabs names it, in lowercase.
     *
     * @param array<int|string, mixed> $listing
     */
    public static function pmsOf(array $listing): string
    {
        return strtolower(Payload::text($listing, 'pms', 40));
    }
}
