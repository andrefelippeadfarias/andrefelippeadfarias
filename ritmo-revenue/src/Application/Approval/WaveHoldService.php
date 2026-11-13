<?php

declare(strict_types=1);

namespace Ritmo\Application\Approval;

use DateTimeImmutable;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Write\Hold\CancellationWave;
use Ritmo\Domain\Write\Hold\WaveConfig;
use Ritmo\Domain\Write\Hold\WaveHold;
use Ritmo\Infrastructure\Db\Repository\WaveHoldRepository;

/**
 * The cancellation-wave hold of a property: opened (or widened) when the run's bookings show a
 * wave, read by the write path before it decides, released only by a person with the approval
 * capability (or by time, when the rules set the hold hours).
 *
 * Reference: AP 06/11 (cuts wait for the front desk to confirm), vigia_seguranca.classificar.
 */
final class WaveHoldService
{
    public function __construct(
        private readonly WaveHoldRepository $holds,
        private readonly CapabilityCheck $capabilities,
        private readonly Clock $clock,
        private readonly WaveConfig $config = new WaveConfig(),
    ) {
    }

    /**
     * Looks for waves among the bookings changed since the instant and holds the property when
     * there is one.
     *
     * @param list<BookingRecord> $bookings
     *
     * @return WaveHold|null the hold now in force, or null
     */
    public function observe(string $propertyId, array $bookings, DateTimeImmutable $since): ?WaveHold
    {
        $waves = (new CancellationWave($this->config))->detect($bookings, $since);
        $found = WaveHold::fromWaves($waves);
        if ($found !== null) {
            $refs = [];
            foreach ($waves as $wave) {
                array_push($refs, ...$wave->bookingRefs);
            }
            $this->holds->hold($propertyId, $found, $refs, $this->clock->now());
        }

        return $this->active($propertyId);
    }

    /**
     * The hold in force for the property, or null.
     */
    public function active(string $propertyId): ?WaveHold
    {
        $open = $this->holds->open($propertyId);

        return $open !== null && $open->isActive($this->clock->now(), $this->config->holdHours) ? $open : null;
    }

    /**
     * A person confirms the cancellations were legitimate: the cuts may go ahead.
     *
     * @throws ApprovalRefused when the user lacks the approval capability
     */
    public function release(string $propertyId, int $userId): bool
    {
        if (!$this->capabilities->userCan($userId, CapabilityCheck::APPROVE)) {
            throw new ApprovalRefused(ApprovalRefused::NO_CAPABILITY, 'Releasing a hold needs the approval capability.');
        }

        return $this->holds->release($propertyId, $userId, $this->clock->now());
    }
}
