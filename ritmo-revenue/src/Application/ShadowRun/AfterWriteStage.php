<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

/**
 * A stage that comes after the write and sends nothing: it reads back, records and reports. It
 * runs even when the emergency switch was engaged during the run, so what already went out is
 * confirmed and recorded (never left out of the change log).
 */
interface AfterWriteStage extends Stage
{
}
