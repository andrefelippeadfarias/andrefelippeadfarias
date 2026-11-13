<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Change;

/**
 * The operation that puts a room night (or a listing value) back as it was before a change.
 */
enum UndoKind: string
{
    /** Write the old values of the changed fields (no field was added). */
    case WriteFields = 'write_fields';

    /** A field was added: delete the date rule and write the old rule again (the system merges, so removing a field needs a deletion). */
    case DeleteThenWrite = 'delete_then_write';

    /** There was no date rule: delete it. */
    case Delete = 'delete';

    /** Set the listing value back (a listing change: human approval like any other). */
    case ListingChange = 'listing_change';

    /** Nothing to put back. */
    case Nothing = 'nothing';
}
