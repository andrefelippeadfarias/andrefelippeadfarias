<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

/**
 * The plugin's log messages in the reader's language. The log keeps them in English (support
 * reads the same words on every site); the diagnostics screen shows each known one translated and
 * any other as it was written.
 */
final class LogText
{
    private function __construct()
    {
    }

    public static function message(string $english): string
    {
        return match ($english) {
            'A run starts.' => __('A run starts.', 'ritmo-revenue'),
            'A run resumes.' => __('A run resumes.', 'ritmo-revenue'),
            'A run ended.' => __('A run ended.', 'ritmo-revenue'),
            'A stage of the run completed.' => __('A stage of the run completed.', 'ritmo-revenue'),
            'A stage of the run failed.' => __('A stage of the run failed.', 'ritmo-revenue'),
            'A run failed with an exception.' => __('A run failed with an unexpected error.', 'ritmo-revenue'),
            'A run of an earlier day was closed as failed.' => __('A run left open on an earlier day was closed as failed.', 'ritmo-revenue'),
            'An open run was stopped at its stage boundary.' => __('An open run was stopped between two of its steps.', 'ritmo-revenue'),
            'An open run was stopped by the emergency switch.' => __('An open run was stopped by the emergency switch.', 'ritmo-revenue'),
            'PriceLabs request finished.' => __('A read of the pricing system finished.', 'ritmo-revenue'),
            'Beds24 request finished.' => __('A read of the PMS finished.', 'ritmo-revenue'),
            'PriceLabs write finished.' => __('A write to the pricing system finished.', 'ritmo-revenue'),
            'Beds24 write finished.' => __('A write to the PMS finished.', 'ritmo-revenue'),
            'PriceLabs sent rows without a date or a price; they were left out.' => __('The pricing system sent rows without a date or a price; they were left out.', 'ritmo-revenue'),
            'Beds24 rejected the access token; renewing it once.' => __('The PMS refused the access token; the plugin renews it once.', 'ritmo-revenue'),
            'Beds24 rejected the access token of a write; renewing it and sending the same body once.' => __('The PMS refused the access token of a write; the plugin renews it and sends the same change once.', 'ritmo-revenue'),
            'Blocked an outgoing request that could change an external system.' => __('A request that could change an external system was blocked.', 'ritmo-revenue'),
            'An alert e-mail could not be sent.' => __('An alert e-mail could not be sent.', 'ritmo-revenue'),
            'An alert e-mail has no recipient: nobody can approve, or everyone opted out.' => __('An alert e-mail has no recipient: nobody can approve, or everyone opted out.', 'ritmo-revenue'),
            'The scheduled runs of a property were paused.' => __('The scheduled runs of a property were paused.', 'ritmo-revenue'),
            'The scheduled runs of a property were resumed.' => __('The scheduled runs of a property were resumed.', 'ritmo-revenue'),
            'A paused property resumed at the end of its pause.' => __('A paused property resumed at the end of its pause.', 'ritmo-revenue'),
            'The scheduler tick failed; the next tick tries again.' => __('The scheduler failed; it tries again on its next turn.', 'ritmo-revenue'),
            'The scheduler failed on a property; the others continue.' => __('The scheduler failed on a property; the others continue.', 'ritmo-revenue'),
            default => $english,
        };
    }
}
