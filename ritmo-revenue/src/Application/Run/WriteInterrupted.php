<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use RuntimeException;

/**
 * The write stage of a run started again after its process died in the middle of the write: the
 * run ends as failed, and the next run reads the systems again before anything is sent (the
 * journal rows of the dead request are read back and classified by its watch stage).
 */
final class WriteInterrupted extends RuntimeException
{
}
