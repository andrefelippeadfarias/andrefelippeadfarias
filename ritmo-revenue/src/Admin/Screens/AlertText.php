<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Domain\Alerts\AlertCode;

/**
 * Alerts in words (admin spec 5.10): a title, what happened and what to do for every code of the
 * catalog, and a label for the facts the raisers record. Facts without a label are not shown
 * (never JSON); the English message stored with the alert is not shown either.
 */
final class AlertText
{
    /** Fact keys shown on the detail, in this order. */
    public const FACTS = ['room', 'night', 'channel', 'ref', 'provider', 'nights', 'units_over', 'sold', 'pms_sold', 'pricing_sold', 'expected', 'value', 'ratio', 'days', 'from', 'to', 'before', 'after', 'last_pushed_at', 'slot', 'groups_open', 'entries', 'count', 'held', 'until', 'mode', 'stage'];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $facts
     */
    public static function title(string $code, array $facts = []): string
    {
        return match ($code) {
            'security_unknown_origin' => __('Booking from an origin you do not know', 'ritmo-revenue'),
            'security_cancellation_burst' => __('Many cancellations in a short time', 'ritmo-revenue'),
            'security_zero_value' => __('Booking with no value', 'ritmo-revenue'),
            'security_zero_value_modified' => __('Booking changed to no value', 'ritmo-revenue'),
            'booking_below_expected' => __('Booking below the expected value', 'ritmo-revenue'),
            'overbooked' => __('More bookings than rooms', 'ritmo-revenue'),
            'sold_mismatch' => __('The PMS and the pricing system disagree on what is sold', 'ritmo-revenue'),
            'push_disabled' => __('Prices are not being sent to the channels', 'ritmo-revenue'),
            'push_stale' => __('Prices were not sent to the channels recently', 'ritmo-revenue'),
            'inventory_closed_without_booking' => __('Night closed without a booking', 'ritmo-revenue'),
            'inventory_stay_off_rule' => __('Minimum stay different from your rules', 'ritmo-revenue'),
            'inventory_arrival_off_rule' => __('Arrival or departure rule different from your rules', 'ritmo-revenue'),
            'write_failed' => __('A change could not be written', 'ritmo-revenue'),
            'recalc_quota_exhausted' => __('Recalculations used up for today', 'ritmo-revenue'),
            'connector_down' => isset($facts['provider']) && $facts['provider'] === 'pricelabs'
                ? __('The pricing system cannot be reached', 'ritmo-revenue')
                : __('The PMS cannot be reached', 'ritmo-revenue'),
            'manual_change_detected' => __('Someone else changed a night', 'ritmo-revenue'),
            'listing_changed_manually' => __('Someone else changed a listing', 'ritmo-revenue'),
            'approval_pending' => __('A plan waits for your approval', 'ritmo-revenue'),
            'approval_expired' => __('A plan expired without a decision', 'ritmo-revenue'),
            'run_late' => __('A scheduled run is late', 'ritmo-revenue'),
            'run_missed' => __('A scheduled run did not happen', 'ritmo-revenue'),
            'scheduler_stalled' => __('The scheduler stopped', 'ritmo-revenue'),
            'vigilance_gap' => __('Bookings were not watched for a while', 'ritmo-revenue'),
            'mode_demoted' => __('The mode was lowered after a failure', 'ritmo-revenue'),
            'market_stale' => __('Market prices are old', 'ritmo-revenue'),
            'market_gap' => __('Nights without market prices', 'ritmo-revenue'),
            'delivery_overdue' => __('Prices not confirmed at the channels yet', 'ritmo-revenue'),
            'review_due' => __('Time for the 12-month review', 'ritmo-revenue'),
            'pms_changes_capped' => __('Too many stay changes for the PMS', 'ritmo-revenue'),
            default => __('Alert', 'ritmo-revenue'),
        };
    }

    public static function explain(string $code): string
    {
        return match ($code) {
            'security_unknown_origin' => __('A booking was created by an origin that is not in your list of known origins.', 'ritmo-revenue'),
            'security_cancellation_burst' => __('Several bookings were cancelled close together. The plugin holds price cuts until a person checks them.', 'ritmo-revenue'),
            'security_zero_value', 'security_zero_value_modified' => __('A booking has a total of zero, which a real guest booking never has.', 'ritmo-revenue'),
            'booking_below_expected' => __('A booking paid less than the price of its nights, after the channel factors.', 'ritmo-revenue'),
            'overbooked' => __('The PMS shows more units sold than the room has on this night.', 'ritmo-revenue'),
            'sold_mismatch' => __('The units sold in the pricing system are not the ones the PMS shows.', 'ritmo-revenue'),
            'push_disabled', 'push_stale' => __('The pricing system has not sent its prices to the channels: guests may see old prices.', 'ritmo-revenue'),
            'inventory_closed_without_booking' => __('A night is closed in the PMS but nobody booked it: it cannot be sold.', 'ritmo-revenue'),
            'inventory_stay_off_rule', 'inventory_arrival_off_rule' => __('The PMS calendar has a stay or arrival rule that your rules would not set.', 'ritmo-revenue'),
            'write_failed' => __('The plugin tried to write a change and the system refused it or did not answer. It stopped there.', 'ritmo-revenue'),
            'recalc_quota_exhausted' => __('The pricing system allows few recalculations per room a day; they were used.', 'ritmo-revenue'),
            'connector_down' => __('The plugin could not read from this system in the last runs.', 'ritmo-revenue'),
            'manual_change_detected' => __('A night was changed outside the plugin. The plugin leaves it as it is until the freeze ends or a person releases it.', 'ritmo-revenue'),
            'listing_changed_manually' => __('The minimum, base or maximum of a listing was changed outside the plugin.', 'ritmo-revenue'),
            'approval_pending', 'approval_expired' => __('The plugin proposed changes that wait for a person.', 'ritmo-revenue'),
            'run_late', 'run_missed', 'scheduler_stalled' => __('The scheduled runs depend on WordPress being visited or on a server cron.', 'ritmo-revenue'),
            'vigilance_gap' => __('For a while no run read the bookings, so changes in that time were checked late.', 'ritmo-revenue'),
            'mode_demoted' => __('After a failed write the property went back to approval mode, so every change waits for a person.', 'ritmo-revenue'),
            'market_stale' => __('The newest competitor prices for the coming nights are older than the rules allow, so the market rules decide on old data.', 'ritmo-revenue'),
            'market_gap' => __('A sales ladder strategy is on, but some of the next nights have no competitor price, so it does not change them.', 'ritmo-revenue'),
            'delivery_overdue' => __('The pricing system took the new prices, but the channels have not shown them yet within the expected time.', 'ritmo-revenue'),
            'review_due' => __('The rules ask for a look at the next 12 months at a regular interval, and that time has come.', 'ritmo-revenue'),
            'pms_changes_capped' => __('The stay rules asked for more changes of minimum stay or arrival and departure in the PMS than one run may write, so none was written. A rule may be set wrong.', 'ritmo-revenue'),
            default => __('The plugin noticed something that needs a look.', 'ritmo-revenue'),
        };
    }

    public static function action(string $code): string
    {
        return match ($code) {
            'security_unknown_origin', 'security_zero_value', 'security_zero_value_modified' => __('Check the booking in the PMS. If the origin is legitimate, add it to the known origins in your rules.', 'ritmo-revenue'),
            'security_cancellation_burst' => __('Check the cancellations in the PMS; when they are legitimate, release the hold of price cuts below.', 'ritmo-revenue'),
            'booking_below_expected' => __('Check the rate plan and the channel of the booking.', 'ritmo-revenue'),
            'overbooked' => __('Move or cancel one of the bookings in the PMS.', 'ritmo-revenue'),
            'sold_mismatch', 'push_disabled', 'push_stale' => __('Open the pricing system and check that its sync with the PMS is on; press Sync Now if needed.', 'ritmo-revenue'),
            'inventory_closed_without_booking' => __('Open the night in the PMS if it should be sold.', 'ritmo-revenue'),
            'inventory_stay_off_rule', 'inventory_arrival_off_rule' => __('Change the night in the PMS, or change your rules if the PMS is right.', 'ritmo-revenue'),
            'write_failed' => __('Check the connection in Settings. The next run reads again and writes only what is missing.', 'ritmo-revenue'),
            'recalc_quota_exhausted' => __('Nothing to do: the next recalculation happens tomorrow.', 'ritmo-revenue'),
            'connector_down' => __('Test the connection in Settings › Connections.', 'ritmo-revenue'),
            'manual_change_detected' => __('Release the freeze of the night in Nights when the plugin may change it again.', 'ritmo-revenue'),
            'listing_changed_manually' => __('Nothing to do if the change was yours; the plugin keeps the new value.', 'ritmo-revenue'),
            'approval_pending', 'approval_expired' => __('Review the plan in Approvals.', 'ritmo-revenue'),
            'run_late', 'run_missed', 'scheduler_stalled', 'vigilance_gap' => __('Make sure a server cron calls wp-cron.php every 5 minutes.', 'ritmo-revenue'),
            'mode_demoted' => __('Resolve the failure, then raise the mode again in Operations.', 'ritmo-revenue'),
            'market_stale', 'market_gap' => __('Import a new file of competitor prices in Market.', 'ritmo-revenue'),
            'delivery_overdue' => __('Check that the pricing system sends prices to the channels; the alert ends by itself when they arrive.', 'ritmo-revenue'),
            'review_due' => __('Open the 12-month review in Reports and save it.', 'ritmo-revenue'),
            'pms_changes_capped' => __('Check the stay rules in Rules; the next run tries again.', 'ritmo-revenue'),
            default => __('Read the facts below and resolve the alert when it is handled.', 'ritmo-revenue'),
        };
    }

    /**
     * The label of a fact, or null when the fact is not shown.
     */
    public static function factLabel(string $key): ?string
    {
        return match ($key) {
            'room' => __('Room', 'ritmo-revenue'),
            'night' => __('Night', 'ritmo-revenue'),
            'channel' => __('Channel', 'ritmo-revenue'),
            'ref' => __('Booking', 'ritmo-revenue'),
            'provider' => __('System', 'ritmo-revenue'),
            'nights' => __('Nights', 'ritmo-revenue'),
            'units_over' => __('Units over the room', 'ritmo-revenue'),
            'sold', 'pms_sold' => __('Sold in the PMS', 'ritmo-revenue'),
            'pricing_sold' => __('Sold in the pricing system', 'ritmo-revenue'),
            'expected' => __('Expected value', 'ritmo-revenue'),
            'value' => __('Value', 'ritmo-revenue'),
            'ratio' => __('Share of the expected value', 'ritmo-revenue'),
            'days' => __('Days', 'ritmo-revenue'),
            'from' => __('From', 'ritmo-revenue'),
            'to' => __('To', 'ritmo-revenue'),
            'before' => __('Before', 'ritmo-revenue'),
            'after' => __('After', 'ritmo-revenue'),
            'last_pushed_at' => __('Last sent to the channels', 'ritmo-revenue'),
            'slot' => __('Scheduled time', 'ritmo-revenue'),
            'groups_open' => __('Changes still open', 'ritmo-revenue'),
            'entries' => __('Entries', 'ritmo-revenue'),
            'count' => __('How many', 'ritmo-revenue'),
            'held' => __('Price cuts held', 'ritmo-revenue'),
            'until' => __('Until', 'ritmo-revenue'),
            'mode' => __('Mode', 'ritmo-revenue'),
            'stage' => __('Step', 'ritmo-revenue'),
            default => null,
        };
    }

    /**
     * Every code of the catalog (for the completeness test).
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (AlertCode $c): string => $c->value, AlertCode::cases());
    }
}
