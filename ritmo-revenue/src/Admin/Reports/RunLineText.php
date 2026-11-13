<?php

declare(strict_types=1);

namespace Ritmo\Admin\Reports;

use Ritmo\Domain\Report\RunLines\RunLine;
use Ritmo\Domain\Report\RunLines\RunLineCode;

/**
 * A stored run report line as a sentence in the reader's language (spec 06, 2.8): the code and
 * its parameters become a translated text; an unknown code is "Unknown report line", never its
 * raw parameters. Runs stored before the change keep their English lines, shown as recorded.
 */
final class RunLineText
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $stored {code, params} as the run summary keeps it
     */
    public static function render(array $stored): string
    {
        $line = RunLine::fromArray($stored);

        return $line === null ? __('Unknown report line', 'ritmo-revenue') : self::of($line);
    }

    /**
     * The lines of a run summary: the coded items when the run has them, else the English lines
     * it recorded (with a note), else nothing.
     *
     * @param array<string, mixed> $summary
     *
     * @return array{lines: list<string>, recorded: bool}
     */
    public static function ofSummary(array $summary): array
    {
        $report = is_array($summary['report'] ?? null) ? $summary['report'] : [];
        if (is_array($report['items'] ?? null)) {
            return ['lines' => array_values(array_map(static fn ($item): string => self::render(is_array($item) ? $item : []), $report['items'])), 'recorded' => false];
        }
        $lines = array_values(array_filter(is_array($report['lines'] ?? null) ? $report['lines'] : [], 'is_string'));

        return ['lines' => $lines, 'recorded' => $lines !== []];
    }

    public static function of(RunLine $line): string
    {
        $p = $line->params;

        return match ($line->code) {
            RunLineCode::LightIdle => __('Light run: no booking moved, no sold-out night flipped, nothing changed by hand. Nothing was decided. Writes sent: 0.', 'ritmo-revenue'),
            RunLineCode::SyncNow => __('Tonight or tomorrow changed: ask the pricing system to send them to the channels now.', 'ritmo-revenue'),
            /* translators: %1$d: number of findings */
            RunLineCode::SecurityFindings => sprintf(_n('%1$d security finding on the bookings; see the alerts.', '%1$d security findings on the bookings; see the alerts.', (int) $p['count'], 'ritmo-revenue'), (int) $p['count']),
            /* translators: %1$d: number of room nights */
            RunLineCode::Overbooked => sprintf(_n('%1$d room night sold beyond the units.', '%1$d room nights sold beyond the units.', (int) $p['count'], 'ritmo-revenue'), (int) $p['count']),
            RunLineCode::WaveHold => __('A cancellation wave holds the price cuts until a person releases it.', 'ritmo-revenue'),
            /* translators: %1$d: changes for the pricing system, %2$d: changes for the PMS */
            RunLineCode::ShadowWouldWrite => sprintf(__('Shadow mode: %1$d change would be written to the pricing system and %2$d to the PMS; nothing was sent.', 'ritmo-revenue'), (int) $p['items'], (int) $p['pms']),
            /* translators: %1$d: number of room nights */
            RunLineCode::WaitingApproval => sprintf(_n('Waiting for approval: %1$d room night; nothing is written before a person approves.', 'Waiting for approval: %1$d room nights; nothing is written before a person approves.', (int) $p['entries'], 'ritmo-revenue'), (int) $p['entries']),
            /* translators: %1$s: why the write was refused, as a sentence */
            RunLineCode::GateRefused => sprintf(__('Not written: %1$s', 'ritmo-revenue'), WriteReasonText::of((string) $p['reason'])),
            /* translators: %1$d: calls sent, %2$d: room nights confirmed in the pricing system, %3$d: room nights confirmed in the PMS */
            RunLineCode::Written => sprintf(__('Written: %1$d calls sent; %2$d room nights confirmed in the pricing system and %3$d in the PMS.', 'ritmo-revenue'), (int) $p['calls'], (int) $p['confirmed'], (int) $p['pms_confirmed']),
            /* translators: %1$s: why the write stopped, as a sentence */
            RunLineCode::WriteStopped => sprintf(__('The write stopped (%1$s). The next run reads the systems again and sends only what is missing.', 'ritmo-revenue'), WriteReasonText::of((string) $p['reason'])),
            /* translators: %1$d: number of entries */
            RunLineCode::Held => sprintf(_n('%1$d entry held by a cancellation wave.', '%1$d entries held by a cancellation wave.', (int) $p['count'], 'ritmo-revenue'), (int) $p['count']),
            /* translators: %1$d: changes for the pricing system, %2$d: changes for the PMS */
            RunLineCode::ValidatorRefused => sprintf(__('Refused by the checks: %1$d change for the pricing system, %2$d for the PMS.', 'ritmo-revenue'), (int) $p['count'], (int) $p['pms']),
            /* translators: %1$d: number of steps */
            RunLineCode::DeliveryOverdue => sprintf(_n('%1$d step not seen on the channels yet; see the alerts.', '%1$d steps not seen on the channels yet; see the alerts.', (int) $p['count'], 'ritmo-revenue'), (int) $p['count']),
            /* translators: %1$d: age in days */
            RunLineCode::MarketStale => sprintf(_n('Market prices are %1$d day old.', 'Market prices are %1$d days old.', (int) $p['days'], 'ritmo-revenue'), (int) $p['days']),
            /* translators: %1$d: number of PMS changes, %2$s: why, as a sentence */
            RunLineCode::PmsSkipped => sprintf(_n('%1$d PMS change skipped: %2$s', '%1$d PMS changes skipped: %2$s', (int) $p['count'], 'ritmo-revenue'), (int) $p['count'], self::skipReason((string) $p['reason'])),
            /* translators: %1$d: number of room nights */
            RunLineCode::StayMergeConflict => sprintf(_n('%1$d room night took the stay rule\'s value over the price rule\'s.', '%1$d room nights took the stay rule\'s value over the price rule\'s.', (int) $p['count'], 'ritmo-revenue'), (int) $p['count']),
        };
    }

    private static function skipReason(string $reason): string
    {
        return match ($reason) {
            'pricing_first' => __('the pricing system had to take the same value first.', 'ritmo-revenue'),
            'already_at_target' => __('the PMS already had the value.', 'ritmo-revenue'),
            'changed_since_read' => __('the PMS changed since it was read; the next run plans again.', 'ritmo-revenue'),
            default => __('for another reason.', 'ritmo-revenue'),
        };
    }
}
