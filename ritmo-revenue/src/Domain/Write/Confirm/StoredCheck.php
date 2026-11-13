<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Confirm;

use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\State\WriteKind;
use Ritmo\Domain\Write\State\WrittenNight;

/**
 * The "stored" level of the confirmation: the night read back after the write holds what the
 * write should have left (WrittenNight::intended(), with the pricing system's merge).
 *
 * - Pricing system: the fields the plugin writes are compared; after a deletion no date rule may
 *   be left; after a group (delete, then write) nothing of the old rule may be left either.
 * - PMS: every field sent, availability against the expected NET value (units minus the night's
 *   bookings) when the write carries it, channel limits as text.
 *
 * A read-back is the same proof as the echo of the response without depending on its format
 * (docs/VERIFY.md, divergence C4). Reference: AP 10/11 (every written rule compared by script),
 * beds24._conferir.
 */
final class StoredCheck
{
    /** What may be left of an old rule after a group, besides the written fields. */
    private const LEFT_AFTER_GROUP = [...ChangeField::WRITABLE_PRICING, ChangeField::FLOOR_FIXED, ChangeField::CEILING_FIXED, ChangeField::EXPIRY];

    private function __construct()
    {
    }

    /**
     * @return list<string> the fields that differ from what the write should have left; empty
     *                      when the night is stored as intended
     */
    public static function differences(WrittenNight $written, NightFields $observed): array
    {
        if ($written->provider === Provider::Pms) {
            return self::pms($written->sent, $observed, $written->expectedAvailable);
        }
        $observed = $observed->of(Provider::Pricing);

        return match ($written->kind) {
            WriteKind::Delete => $observed->isEmpty() ? [] : [ChangeField::RULE],
            WriteKind::Rewrite => $written->intended()->only(self::LEFT_AFTER_GROUP)->differs($observed->only(self::LEFT_AFTER_GROUP)),
            WriteKind::Write => $written->intended()->only(ChangeField::WRITABLE_PRICING)->differs($observed->only(ChangeField::WRITABLE_PRICING)),
        };
    }

    /**
     * The PMS fields sent that the re-read does not show.
     *
     * @return list<string>
     */
    public static function pms(NightFields $sent, NightFields $observed, ?int $expectedAvailable = null): array
    {
        $differs = [];
        foreach ($sent->all() as $field => $value) {
            $expected = $field === ChangeField::PMS_AVAILABLE && $expectedAvailable !== null ? $expectedAvailable : $value;
            if (!NightFields::same($expected, $observed->get($field))) {
                $differs[] = $field;
            }
        }

        return $differs;
    }
}
