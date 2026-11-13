<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Labels;

/**
 * The events of the Operations screen in words (admin spec 5.16): what changed, from what to
 * what; never the stored codes.
 */
final class OperationsText
{
    private function __construct()
    {
    }

    /**
     * @param array{kind: string, from: string, to: string} $event
     */
    public static function event(array $event): string
    {
        $from = $event['from'];
        $to = $event['to'];

        return match ($event['kind']) {
            /* translators: 1: mode before, 2: mode after */
            'mode' => sprintf(__('Mode changed from %1$s to %2$s', 'ritmo-revenue'), Labels::mode($from), Labels::mode($to)),
            'approval' => match ($to) {
                'approved' => __('A plan was approved', 'ritmo-revenue'),
                'rejected' => __('A plan was refused', 'ritmo-revenue'),
                'written' => __('An approved plan was written', 'ritmo-revenue'),
                default => __('A plan changed status', 'ritmo-revenue'),
            },
            'emergency' => $to === 'engaged' ? __('Emergency switch engaged', 'ritmo-revenue') : __('Emergency switch released', 'ritmo-revenue'),
            'wave_release' => __('The hold of price cuts after a wave of cancellations was released', 'ritmo-revenue'),
            'freeze_release' => __('Frozen nights were released', 'ritmo-revenue'),
            'undo_request' => $to === 'written' ? __('An undo was written', 'ritmo-revenue') : __('An undo was written in part', 'ritmo-revenue'),
            'listing_values' => __('The listing values read differ from the stored ones; the stored values follow', 'ritmo-revenue'),
            'pause' => $to === 'resumed' ? __('Scheduled runs resumed', 'ritmo-revenue') : __('Scheduled runs paused', 'ritmo-revenue'),
            /* translators: %s: number of the new version */
            'rules_version' => sprintf(__('Rules saved as version %s', 'ritmo-revenue'), $to),
            'order' => $to === '' ? __('An order was ended', 'ritmo-revenue') : __('An order was created', 'ritmo-revenue'),
            'competitor' => $to === '' ? __('A competitor was archived', 'ritmo-revenue') : __('A competitor was added or changed', 'ritmo-revenue'),
            /* translators: %s: number of prices */
            'market_import' => sprintf(__('Market prices imported: %s', 'ritmo-revenue'), $to),
            default => __('A setting changed', 'ritmo-revenue'),
        };
    }

    /**
     * Who did it: a person's display name, or the plugin.
     */
    public static function actor(string $actor): string
    {
        if (preg_match('/^user:(\d+)$/', $actor, $match) === 1 && (int) $match[1] > 0) {
            return PeopleNames::name((int) $match[1]);
        }

        return __('Ritmo Revenue', 'ritmo-revenue');
    }
}
