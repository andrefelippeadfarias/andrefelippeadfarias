<?php

declare(strict_types=1);

namespace Ritmo\Application\Approval;

use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Support\Days;

/**
 * Cuts a plan rebuilt from fresh reads down to the entries a person approved: an entry is kept
 * only when the approval names it with the same sent fields and order; an entry whose fields
 * changed needs a new approval; a group (a deletion and its rewrite) is kept only whole. A plan of
 * another day than the approved one keeps nothing.
 *
 * The result is a draft: the caller validates it again and asks the write gate for a token with
 * the grant.
 */
final class ApprovedSubset
{
    private function __construct()
    {
    }

    /**
     * @param array<string, float> $bases room => listing base of the rebuilt plan
     */
    public static function restrict(ApprovalGrant $grant, Plan $fresh, array $bases): RestrictedPlan
    {
        $approved = [];
        foreach ($grant->approvedEntries as $entry) {
            $approved[$entry->key()] = $entry;
        }
        $sameDay = $grant->propertyId === $fresh->propertyId && Days::key($grant->planDay) === Days::key($fresh->today);
        $entries = PlanEntries::of($fresh, $bases);
        $changed = [];
        $missing = [];
        $groupsOut = [];
        foreach ($entries as $entry) {
            $was = $approved[$entry->key()] ?? null;
            if ($was === null || !$sameDay) {
                $missing[] = $entry->key();
                $groupsOut[$entry->group()] = true;
            } elseif (!$was->sameAs($entry)) {
                $changed[] = $entry->key();
                $groupsOut[$entry->group()] = true;
            }
        }
        $drop = ['item' => [], 'deletion' => [], 'listing' => []];
        $positions = ['item' => 0, 'deletion' => 0, 'listing' => 0];
        foreach ($entries as $entry) {
            $index = $positions[$entry->kind]++;
            if (isset($groupsOut[$entry->group()])) {
                $drop[$entry->kind][] = $index;
                if (!in_array($entry->key(), $changed, true) && !in_array($entry->key(), $missing, true)) {
                    $changed[] = $entry->key();
                }
            }
        }

        return new RestrictedPlan($fresh->without($drop['item'], $drop['deletion'], $drop['listing']), $changed, $missing);
    }
}
