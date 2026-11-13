<?php

declare(strict_types=1);

namespace Ritmo\Contracts\Exception;

/**
 * The pricing system refused a write because it has no pricing data for a date yet (HTTP 400
 * "Pricing data for this date isn't available yet"): the listing needs a recalculation first.
 * Nothing of the call was stored. It is not retried in the same run.
 */
final class NotReady extends ConnectorException
{
}
