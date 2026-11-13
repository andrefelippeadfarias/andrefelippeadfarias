<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\ThirdParty;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;

/**
 * Finds the room nights changed by someone other than the plugin, by comparing what this run read
 * with the latest observation of the night (a run's read, or the read-back after a write of the
 * plugin, which is how the plugin's own changes stop being differences). The pricing API has no
 * change log, so a state comparison is the only proof (divergence C7 of docs/VERIFY.md).
 *
 * Not a third-party change:
 * - a first observation (the baseline), a night before today;
 * - a night with a write of the plugin after the previous observation whose outcome is unknown
 *   (the resume classifies it);
 * - PMS availability (bookings and cancellations move it);
 * - a date rule gone after its lead-time expiry;
 * - a PMS stay or restriction equal to what the pricing system's push sets there;
 * - the reason text of a date rule (never part of the observation).
 *
 * Reference: SKILL.md (a user-log action without the "api_" prefix is a manual change), ordens.py
 * (registrar_manual); AP 01/11 (the push overwrites PMS stays), AP 29/10 (the screen copies the reason).
 */
final class ManualChangeDetector
{
    /**
     * @param array<string, Observation> $previous   key => the latest observation before this run
     * @param list<Observation>          $current    this run's reads
     * @param array<string, bool>        $unresolved key => true for nights with an unresolved write of the plugin
     * @param array<string, NightFields> $pushed     PMS key => the PMS values the pricing system's push sets on that night
     *
     * @return list<ThirdPartyChange>
     */
    public function detect(array $previous, array $current, array $unresolved, array $pushed, DateTimeImmutable $today): array
    {
        $changes = [];
        foreach ($current as $observation) {
            $last = $previous[$observation->key()] ?? null;
            if ($last === null || $observation->night < Days::normalize($today) || ($unresolved[$observation->key()] ?? false)) {
                continue;
            }
            $before = $last->fields->without([ChangeField::PMS_AVAILABLE]);
            $after = $observation->fields->without([ChangeField::PMS_AVAILABLE]);
            if (self::expired($observation->provider, $before, $after, $today)) {
                continue;
            }
            $push = $pushed[$observation->key()] ?? NightFields::none();
            $fields = [];
            foreach ($before->differs($after) as $field) {
                if (!($push->has($field) && NightFields::same($push->get($field), $after->get($field)))) {
                    $fields[$field] = [$before->get($field), $after->get($field)];
                }
            }
            if ($fields !== []) {
                $changes[] = new ThirdPartyChange($observation->provider, $observation->roomCode, $observation->night, $fields, $last->observedAt);
            }
        }

        return $changes;
    }

    /**
     * The date rule disappeared because its lead-time expiry (the last day it applies) passed.
     */
    private static function expired(Provider $provider, NightFields $before, NightFields $after, DateTimeImmutable $today): bool
    {
        $expiry = $before->get(ChangeField::EXPIRY);

        return $provider === Provider::Pricing
            && $after->isEmpty()
            && is_string($expiry)
            && Days::isValid($expiry)
            && Days::date($expiry) < Days::normalize($today);
    }
}
