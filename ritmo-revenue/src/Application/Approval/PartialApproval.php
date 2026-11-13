<?php

declare(strict_types=1);

namespace Ritmo\Application\Approval;

/**
 * Closes a partial approval over the groups: selecting one member of a group (a deletion and the
 * item that writes its night again) selects the whole group. The subset is validated again by
 * the approved write (it reads the calendar again and runs the Validator), which refuses it as a
 * whole when it breaks a relation or a band.
 *
 * Reference: operations spec 6.5; ENGINE.md 1.8 (L2: a deletion and its rewrite go together).
 */
final class PartialApproval
{
    private function __construct()
    {
    }

    /**
     * @param list<PlanEntry> $entries
     * @param list<string>    $selected entry keys
     *
     * @throws ApprovalRefused when a selected key is not an entry of the plan
     */
    public static function close(array $entries, array $selected): ApprovalSelection
    {
        $byKey = [];
        foreach ($entries as $entry) {
            $byKey[$entry->key()] = $entry;
        }
        $groups = [];
        foreach ($selected as $key) {
            if (!isset($byKey[$key])) {
                throw new ApprovalRefused(ApprovalRefused::UNKNOWN_ENTRY, sprintf('"%s" is not an entry of this plan.', $key));
            }
            $groups[$byKey[$key]->group()] = true;
        }
        $approved = [];
        $rejected = [];
        foreach ($entries as $entry) {
            if (isset($groups[$entry->group()])) {
                $approved[] = $entry->key();
            } else {
                $rejected[] = $entry->key();
            }
        }

        return new ApprovalSelection($approved, $rejected, array_values(array_diff($approved, $selected)));
    }
}
