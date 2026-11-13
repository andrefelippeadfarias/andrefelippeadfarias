<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use Ritmo\Application\Run\StayAssembly;
use Ritmo\Domain\Stay\Write\PmsChangeEntry;
use Ritmo\Domain\Support\Days;

/**
 * The PMS changes of a run as plain arrays for the next stages and the screens: what would be
 * written (sealed with the plan), what the check refused (with its code) and what a frozen night
 * kept out. Fields by their change-field names; no price, no booking.
 */
final class PmsState
{
    public const SEALED = 'would_write';
    public const REFUSED = 'rejected';
    public const FROZEN = 'frozen';

    private function __construct()
    {
    }

    /**
     * @return list<array{room: string, night: string, set: array<string, int|string|null>, before: array<string, int|string|null>, reason: string, status: string, code: string|null}>
     */
    public static function of(StayAssembly $stay): array
    {
        $out = [];
        foreach ($stay->assembled->finalValidation?->plan?->plan->pmsChanges ?? [] as $entry) {
            $out[] = self::row($entry, self::SEALED, null);
        }
        foreach ($stay->batch?->check->refused ?? [] as $refused) {
            $out[] = self::row($refused['entry'], self::REFUSED, $refused['code']);
        }
        foreach ($stay->batch?->frozenOut ?? [] as $entry) {
            $out[] = self::row($entry, self::FROZEN, null);
        }

        return $out;
    }

    /**
     * @return array{room: string, night: string, set: array<string, int|string|null>, before: array<string, int|string|null>, reason: string, status: string, code: string|null}
     */
    private static function row(PmsChangeEntry $entry, string $status, ?string $code): array
    {
        return [
            'room' => $entry->roomCode,
            'night' => Days::key($entry->night),
            'set' => $entry->target->all(),
            'before' => $entry->before->all(),
            'reason' => $entry->reason,
            'status' => $status,
            'code' => $code,
        ];
    }
}
