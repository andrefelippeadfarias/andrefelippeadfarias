<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

use Closure;

/**
 * Dedupe, reopening and automatic resolution of alerts (operations spec 5.9).
 */
final class AlertLifecycleRules
{
    /**
     * @param AlertRecord|null $latest the newest alert stored under the draft's key
     */
    public static function decide(AlertDraft $draft, ?AlertRecord $latest): RaiseAction
    {
        if ($latest === null) {
            return RaiseAction::Insert;
        }
        if ($latest->status->isActive()) {
            return RaiseAction::Refresh;
        }
        if ($draft->code->lifecycle() === AlertLifecycle::Condition) {
            return RaiseAction::Insert;
        }

        return $draft->evidenceAt !== null && $latest->resolvedAt !== null && $draft->evidenceAt > $latest->resolvedAt
            ? RaiseAction::Insert
            : RaiseAction::Ignore;
    }

    /**
     * The condition alerts a run that checked their scope did not raise again: they are resolved.
     * Events are never resolved here.
     *
     * @param list<AlertRecord>              $active     open or acknowledged alerts of the codes the run checked
     * @param array<string, true>            $raisedKeys dedupe keys the run raised
     * @param Closure(AlertRecord): bool|null $inScope   limits the resolution to what the run checked (rooms, nights); null: everything given
     *
     * @return list<AlertRecord>
     */
    public static function stale(array $active, array $raisedKeys, ?Closure $inScope = null): array
    {
        return array_values(array_filter(
            $active,
            static fn (AlertRecord $a): bool => $a->status->isActive()
                && $a->lifecycle() === AlertLifecycle::Condition
                && !isset($raisedKeys[$a->dedupeKey])
                && ($inScope === null || $inScope($a))
        ));
    }
}
