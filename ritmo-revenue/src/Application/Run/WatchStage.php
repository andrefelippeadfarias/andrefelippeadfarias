<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Application\Alerts\AlertService;
use Ritmo\Application\Alerts\WatchInput;
use Ritmo\Application\Alerts\WatchService;
use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Application\Approval\WaveHoldService;
use Ritmo\Application\ConnectorSource;
use Ritmo\Application\Schedule\MovementTracker;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Application\ShadowRun\Stage;
use Ritmo\Application\ShadowRun\StageResult;
use Ritmo\Application\Verify\ThirdPartyWatch;
use Ritmo\Application\Write\Resume\BrokenGroup;
use Ritmo\Domain\Alerts\OperationAlerts;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Domain\Report\BookedUnits;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Hold\CancellationWave;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\State\WrittenNight;
use Ritmo\Domain\Write\ThirdParty\Observation;
use Ritmo\Domain\Write\ThirdParty\ThirdPartyChange;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;
use Ritmo\Infrastructure\Db\Repository\DetectedFreezeRepository;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Infrastructure\Db\Repository\NightObservationRepository;
use Ritmo\Infrastructure\Db\Repository\WaveHoldRepository;

/**
 * Watches the property before anything is decided (operations spec O21): reads the bookings that
 * moved since the previous read (one list for the movement and the watch, O50), raises the
 * security, below-expected, overbooking and closed-night alerts, observes a cancellation wave
 * (it holds price cuts until a person releases it), stores the listing values read when they
 * changed (ListingWatch: alerted unless the plugin wrote them), reads back what earlier writes left
 * with an unknown outcome (JournalWatch, in every mode: a night of a write whose outcome nobody read
 * is neither the plugin's nor someone else's until it is read), and compares what this run read with
 * what earlier runs read or wrote, freezing every night someone else changed (it is never
 * overwritten while frozen). It leaves for the decision the nights closed in the PMS without a
 * booking, the deletion-and-rewrite groups left open (repaired first by a run that may write;
 * their alert is raised again by every run until then) and, in a light run, the room nights the
 * run may touch.
 *
 * A booking read that fails twice raises connector_down and fails the run: with the bookings
 * unwatched nothing is decided nor written.
 */
final class WatchStage implements Stage
{
    public const NAME = 'watch';

    public function __construct(
        private readonly ConnectorSource $connectors,
        private readonly MovementTracker $movements,
        private readonly AlertService $alerts,
        private readonly WaveHoldRepository $holds,
        private readonly NightObservationRepository $observations,
        private readonly DetectedFreezeRepository $freezes,
        private readonly CapabilityCheck $capabilities,
        private readonly LightScope $light,
        private readonly InventorySnapshotRepository $snapshots,
        private readonly BookingRepository $bookings,
        private readonly JournalWatch $journal,
        private readonly ListingWatch $listings,
        private readonly Clock $clock,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(RunContext $context): StageResult
    {
        $pms = $this->connectors->pms();
        $read = $this->movements->read($context->property->id, $pms, $context->rooms, $context->rules->lightRun(), $context->rules->schedule()->maxMovementLookbackDays, $context->runId);
        if (!$read->succeeded()) {
            throw new BookingsUnwatched('The bookings could not be read: nothing is decided nor written while they are not watched.');
        }
        $inventory = $this->inventory($context);
        $stays = $this->bookings->overlapping($context->horizon->first(), Days::add($context->horizon->last(), 1));
        $rules = $context->rules;
        $watch = new WatchService($this->alerts, $rules->alerts(), new CancellationWave($rules->waves()), $this->snapshots);
        $waves = new WaveHoldService($this->holds, $this->capabilities, $this->clock, $rules->waves());
        $thirdParty = new ThirdPartyWatch($this->observations, $this->freezes, $this->capabilities, $this->clock, $rules->thirdParty());
        $outcome = $watch->watch(new WatchInput(
            $context->property->id,
            $read->bookings,
            $read->window->since,
            $inventory,
            BookedUnits::byRoomNight($stays, $context->horizon),
            array_combine(array_map(static fn (RoomType $r): string => $r->code, $context->rooms), array_map(static fn (RoomType $r): int => $r->units, $context->rooms)),
            $context->horizon,
            $context->runId,
        ));
        $hold = $waves->observe($context->property->id, $read->bookings, $read->window->since);
        $listingChanges = $this->listings->inspect($context);
        $settled = $this->journal->settle($context);
        $third = $thirdParty->inspect($context->property, $this->observations($context, $inventory), JournalWatch::ownNights($settled), PushedFields::of($context->rules->stay()->pricingSync, $this->connectors->pricingStaySync(), $context->pricingNights(), $context->overrides()), $context->runId);
        $this->alerts->raiseAll(array_map(fn (ThirdPartyChange $c) => OperationAlerts::manualChange(
            $context->property->id,
            $c->provider->value,
            $c->roomCode,
            $c->night,
            array_map(static fn (array $pair): mixed => $pair[0], $c->fields),
            array_map(static fn (array $pair): mixed => $pair[1], $c->fields),
            $this->clock->now(),
        ), $third->changes), $context->runId);

        $state = ['blocked' => array_keys($outcome->blocked), 'broken_groups' => array_map(static fn (BrokenGroup $g): array => $g->toArray(), $settled->broken)];
        $summary = [
            'bookings_read' => count($read->bookings),
            'settled' => count($settled->rows),
            'listing_changes' => count($listingChanges),
            'groups_open' => count($settled->broken),
            'movements' => count($read->batch?->movements ?? []),
            'security_findings' => count($outcome->security),
            'below_expected' => count($outcome->belowExpected),
            'overbooked' => count($outcome->overbooked),
            'closed_without_booking' => count($outcome->closed),
            'wave_hold' => $hold !== null || $waves->active($context->property->id) !== null,
            'manual_changes' => count($third->changes),
            'nights_frozen' => array_sum(array_map(static fn ($f): int => count($f->nights), $third->freezes)),
        ];
        if ($context->kind === RunKind::Light->value) {
            $scope = $this->light->scope($context, $read->batch?->movements ?? [], $inventory, $third->changes, count($outcome->overbooked));
            $state['light_filter'] = $scope['filter'];
            $summary['light'] = $scope['summary'];
        }

        return new StageResult($summary, $state);
    }

    /**
     * The PMS calendar this run read, room code => night => night.
     *
     * @return array<string, array<string, \Ritmo\Domain\Model\InventoryNight>>
     */
    private function inventory(RunContext $context): array
    {
        $out = [];
        foreach ($context->rooms as $room) {
            $out[$room->code] = $this->snapshots->latest($room->code, $context->horizon->first(), $context->horizon->last(), $context->capturedAt());
        }

        return $out;
    }

    /**
     * What this run read of every room night of the horizon, before any write: the date rule of
     * the pricing system (none when the night has no rule) and the PMS calendar.
     *
     * @param array<string, array<string, \Ritmo\Domain\Model\InventoryNight>> $inventory
     *
     * @return list<Observation>
     */
    private function observations(RunContext $context, array $inventory): array
    {
        $at = $context->capturedAt() ?? $this->clock->now();
        $overrides = $context->overrides();
        $listings = $context->listings();
        $out = [];
        foreach ($context->rooms as $room) {
            foreach ($context->horizon->keys() as $night) {
                if (isset($listings[$room->code])) {
                    $out[] = new Observation(Provider::Pricing, $room->code, Days::date($night), NightFields::fromOverride($overrides[$room->code][$night] ?? null), $at);
                }
                if (isset($inventory[$room->code][$night])) {
                    $out[] = new Observation(Provider::Pms, $room->code, Days::date($night), NightFields::fromInventory($inventory[$room->code][$night]), $at);
                }
            }
        }

        return $out;
    }
}
