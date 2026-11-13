<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Write\Support;

use Error;

/**
 * The PHP process "dies" mid-run (a fatal error, the maximum execution time): an Error no code of
 * the write path catches. The journal row of the step in flight stays "sending".
 */
final class SimulatedCrash extends Error
{
}
