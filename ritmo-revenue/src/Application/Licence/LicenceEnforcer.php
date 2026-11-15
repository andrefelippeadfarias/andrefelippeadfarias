<?php

declare(strict_types=1);

namespace Ritmo\Application\Licence;

use Ritmo\Contracts\Logger;
use Ritmo\Domain\Licence\LicenceReason;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;

/**
 * What the licence means for the runs and the modes. It reads the stored verdict only (never the
 * provider), so it can be asked at the start of a run.
 *
 * - modeAtRunStart(): the mode a run starts in. While the licence allows writing it is the
 *   property's mode; once it does not (grace over, no licence), the run starts in shadow mode and
 *   a property in approval or autonomous mode is put back in shadow mode for good. A run already
 *   writing is never asked again, so nothing is cut in the middle of a write.
 * - unmetToLeaveShadow(): the condition the mode change adds to leaving shadow mode.
 * - allowsAi(): the AI assistant works only with a licence that allows writing.
 */
final class LicenceEnforcer
{
    public const UNMET_CODE = 'licence';

    public function __construct(
        private readonly LicenceVerdictSource $licence,
        private readonly ModeLowering $lowering,
        private readonly Logger $logger,
    ) {
    }

    public function modeAtRunStart(Property $property): PropertyMode
    {
        $verdict = $this->licence->verdict();
        if ($verdict->allowsWriting() || $property->mode === PropertyMode::Shadow) {
            return $property->mode;
        }
        $reason = sprintf('The licence does not allow writing (%s): back to shadow mode.', $verdict->reason === LicenceReason::NONE ? $verdict->standing->value : $verdict->reason);
        $this->lowering->toShadow($property, $reason);
        $this->logger->warning('A property went back to shadow mode because of the licence.', [
            'property_id' => $property->id,
            'from' => $property->mode->value,
            'standing' => $verdict->standing->value,
            'reason' => $verdict->reason,
        ]);

        return PropertyMode::Shadow;
    }

    /**
     * @return array{code: string, params: array<string, int|string>, text: string}|null null when the licence allows leaving shadow mode
     */
    public function unmetToLeaveShadow(): ?array
    {
        $verdict = $this->licence->verdict();
        if ($verdict->allowsWriting()) {
            return null;
        }

        return ['code' => self::UNMET_CODE, 'params' => ['standing' => $verdict->standing->value, 'reason' => $verdict->reason], 'text' => 'A valid licence is needed to leave shadow mode.'];
    }

    public function allowsAi(): bool
    {
        return $this->licence->verdict()->allowsAi();
    }
}
