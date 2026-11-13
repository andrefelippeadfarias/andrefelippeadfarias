<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Setup;

use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\RoomType;

/**
 * The safety values of the wizard's step 7 (admin spec 3.4): the site-wide limits of the rule set
 * and the reference minimum of each room. Their fingerprint tells whether what a person confirmed
 * is still what is in force.
 */
final class SafetyValues
{
    /** Rule-set keys of the safety step, edited there; never_raise_today is shown locked. */
    public const RULE_KEYS = ['floors.fraction_of_reference_min', 'safety.weekly_listing_change_max', 'write.listing_changes_enabled', 'modes.autonomous_enabled'];

    private function __construct()
    {
    }

    /**
     * @param list<RoomType> $rooms
     */
    public static function fingerprint(RuleSet $rules, array $rooms): string
    {
        $values = array_map(static fn (string $key): mixed => $rules->get($key), self::RULE_KEYS);
        foreach ($rooms as $room) {
            $values[] = [$room->code, $room->referenceMin];
        }

        return hash('sha256', (string) json_encode($values, JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * The rule set with some dotted keys changed (validated again by RuleSet::fromArray).
     *
     * @param array<string, mixed> $changes
     */
    public static function apply(RuleSet $rules, array $changes): RuleSet
    {
        $data = $rules->toArray();
        foreach ($changes as $key => $value) {
            $node = &$data;
            foreach (explode('.', $key) as $part) {
                $node = &$node[$part];
            }
            $node = $value;
            unset($node);
        }

        return RuleSet::fromArray($data);
    }
}
