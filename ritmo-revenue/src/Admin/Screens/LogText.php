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
            'A run could not start: no room of the property is mapped to the PMS yet.' => __('A run could not be made: no room of the property is linked to the PMS yet (expected while the setup is not finished: save the rooms step).', 'ritmo-revenue'),
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
            'A background step of the plugin failed.' => __('A background step of the plugin failed.', 'ritmo-revenue'),
            'The scheduler tick waits for the database update.' => __('The scheduler waits for the database update.', 'ritmo-revenue'),
            'The stored rules do not pass validation after the update.' => __('The stored rules do not pass validation after the update.', 'ritmo-revenue'),
            'A run was stopped by PHP and closed as failed.' => __('A run was stopped by the server (memory or time) and closed as failed.', 'ritmo-revenue'),
            'Claude answered.' => __('Claude answered.', 'ritmo-revenue'),
            'Claude request failed.' => __('A request to Claude failed.', 'ritmo-revenue'),
            'Claude request finished.' => __('A request to Claude finished.', 'ritmo-revenue'),
            'Claude request got no answer.' => __('A request to Claude got no answer.', 'ritmo-revenue'),
            'Claude connection tested.' => __('The connection to Claude was tested.', 'ritmo-revenue'),
            'Claude connection is set up wrongly on this site.' => __('The connection to Claude is set up wrongly on this site.', 'ritmo-revenue'),
            'A licence key was saved.' => __('A licence key was saved.', 'ritmo-revenue'),
            'The licence was checked.' => __('The licence was checked.', 'ritmo-revenue'),
            'A screen action failed.' => __('An action of a screen failed; the reference is the one the screen showed.', 'ritmo-revenue'),
            'The licence key was removed.' => __('The licence key was removed.', 'ritmo-revenue'),
            'The scheduled licence check failed.' => __('The scheduled licence check failed.', 'ritmo-revenue'),
            'A property went back to shadow mode because of the licence.' => __('A property went back to shadow mode because of the licence.', 'ritmo-revenue'),
            'A verified update is available.' => __('A verified update is available.', 'ritmo-revenue'),
            'An update package was verified.' => __('An update package was verified.', 'ritmo-revenue'),
            'A downloaded update package was refused.' => __('A downloaded update package was refused.', 'ritmo-revenue'),
            'Release metadata was refused.' => __('The information of an update was refused.', 'ritmo-revenue'),
            'The update server could not be reached.' => __('The update server could not be reached.', 'ritmo-revenue'),
            'The update server did not answer with the release.' => __('The update server did not answer with the release.', 'ritmo-revenue'),
            'Blocked an outgoing request to a host this channel may not reach.' => __('A request to a server this channel may not reach was blocked.', 'ritmo-revenue'),
            default => $english,
        };
    }
}
