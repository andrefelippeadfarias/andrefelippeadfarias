<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

use RuntimeException;

/**
 * A refusal of a screen action whose message is already a translated sentence for the person
 * (a typed confirmation that does not match, a text refused by the privacy guard).
 */
final class UserFacing extends RuntimeException
{
}
