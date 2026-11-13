<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

use Ritmo\Application\Alerts\AlertService;
use Ritmo\Contracts\Exception\AuthFailed;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Contracts\PmsConnector;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertDraft;
use Ritmo\Domain\Alerts\OperationAlerts;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Operations\LightRunConfig;
use Ritmo\Domain\Operations\MovementDetector;
use Ritmo\Domain\Operations\MovementWindow;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Schedule\BookingMovementRepository;
use Ritmo\Infrastructure\Schedule\OperationStateRepository;
use Ritmo\Infrastructure\Support\Sleeper;

/**
 * Reads the bookings changed since the previous read of a property and turns them into movement
 * (operations spec 4.2 step 1 and 4.3).
 *
 * The read starts at the cursor minus the overlap margin; the cursor (the instant just before the
 * read) moves only after a successful read (O11). A failed read is tried once more after ten
 * seconds, except a rate limit (the client already waited) or a refused connection; a second
 * failure raises connector_down, critical: the bookings are not being watched, and the run must
 * decide nothing. The processed versions are recorded, so the overlap never counts a booking twice
 * (O12). Reference: beds24.ler_modificadas, SKILL.md "--desde <início da rodada anterior - 5 min>".
 */
final class MovementTracker
{
    public const RETRY_AFTER_SECONDS = 10;

    public function __construct(
        private readonly OperationStateRepository $states,
        private readonly BookingMovementRepository $ledger,
        private readonly AlertService $alerts,
        private readonly Sleeper $sleeper,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param list<RoomType> $rooms the property's room types
     */
    public function read(string $propertyId, PmsConnector $pms, array $rooms, LightRunConfig $config, int $maxLookbackDays, ?int $runId = null): MovementRead
    {
        $now = $this->clock->now();
        $window = MovementWindow::compute($this->states->get($propertyId)->movementCursor, $now, $config, $maxLookbackDays);
        try {
            $bookings = $this->fetch($pms, $window);
        } catch (ConnectorException $e) {
            $this->alerts->raise(OperationAlerts::connectorDown($propertyId, $pms->id(), true, $e instanceof AuthFailed), $runId);

            return MovementRead::failed($window, $e);
        }
        $this->alerts->resolveKey(AlertDraft::keyFor(AlertCode::ConnectorDown, $propertyId, [$pms->id()]));
        if ($window->gapFrom !== null) {
            $this->alerts->raise(OperationAlerts::vigilanceGap($propertyId, $window->gapFrom, $window->since), $runId);
        }

        $codes = array_fill_keys(array_map(static fn (RoomType $r): string => $r->code, $rooms), true);
        $refs = array_map(static fn (BookingRecord $b): string => $b->pmsRef, $bookings);
        $batch = (new MovementDetector())->detect($bookings, $this->ledger->lastVersions($propertyId, $pms->id(), $refs), $window, $codes);
        $this->ledger->record($propertyId, $pms->id(), $batch->versions, $now, $runId);
        $this->states->setMovementCursor($propertyId, $now);

        return MovementRead::read($window, $bookings, $batch);
    }

    /**
     * @return list<BookingRecord>
     */
    private function fetch(PmsConnector $pms, MovementWindow $window): array
    {
        try {
            return $pms->readBookingsModifiedSince($window->since);
        } catch (RateLimited|AuthFailed $e) {
            throw $e;
        } catch (ConnectorException) {
            $this->sleeper->sleep(self::RETRY_AFTER_SECONDS);

            return $pms->readBookingsModifiedSince($window->since);
        }
    }
}
