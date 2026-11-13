<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

/**
 * Why a light run goes on to decide. Without any of these it ends with the three-line report
 * (reference SKILL.md: "rotina leve sem movimento ... não grava nada").
 */
enum LightTrigger: string
{
    /** A booking was made, changed or cancelled on a night of the horizon. */
    case Movement = 'movement';
    /** A night near today sold out or reopened (a sale, a block, a room move). */
    case SoldOutFlip = 'sold_out_flip';
    /** Someone changed a night in one of the systems by hand. */
    case ManualChange = 'manual_change';
    /** A night is sold beyond the units of its room and was not alerted before. */
    case Overbooking = 'overbooking';
}
