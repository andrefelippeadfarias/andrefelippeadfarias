<?php

declare(strict_types=1);

namespace Ritmo\Tests\Fakes;

use Ritmo\Application\Licence\LicenceVerdictSource;
use Ritmo\Domain\Licence\LicenceReason;
use Ritmo\Domain\Licence\LicenceStanding;
use Ritmo\Domain\Licence\LicenceVerdict;

/**
 * A licence whose verdict a test sets by hand: active (writing modes and the assistant allowed)
 * unless the test lapses it or removes it.
 */
final class FixedLicence implements LicenceVerdictSource
{
    public function __construct(private LicenceVerdict $verdict = new LicenceVerdict(LicenceStanding::Active, LicenceReason::NONE))
    {
    }

    public function verdict(): LicenceVerdict
    {
        return $this->verdict;
    }

    public function lapse(string $reason = LicenceReason::EXPIRED): void
    {
        $this->verdict = new LicenceVerdict(LicenceStanding::Lapsed, $reason);
    }

    public function remove(): void
    {
        $this->verdict = new LicenceVerdict(LicenceStanding::Unlicensed, LicenceReason::NO_KEY);
    }

    public function activate(): void
    {
        $this->verdict = new LicenceVerdict(LicenceStanding::Active, LicenceReason::NONE);
    }
}
