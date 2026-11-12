<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

/**
 * The "plane" an amount is expressed in. The same night has a different number in each one and
 * the measured price factors convert between them (plan amount = sent amount x factor).
 */
enum PricePlan: string
{
    /** The rate sent by the pricing system to the PMS and channels. Factor 1 by definition. */
    case Sent = 'sent';

    /** What a guest without any loyalty discount sees on the sales channel. */
    case PublicRate = 'public';

    /** The lowest price shown on the channel after its deepest standing discount. */
    case Discounted = 'discounted';
}
