<?php

declare(strict_types=1);

namespace Ritmo\Application\Licence;

use Ritmo\Domain\Licence\LicenceVerdict;

/**
 * Where the rest of the plugin asks how the licence stands. Answers from what is stored, never by
 * asking the provider, so it is safe at the start of a run and on every screen.
 */
interface LicenceVerdictSource
{
    public function verdict(): LicenceVerdict;
}
