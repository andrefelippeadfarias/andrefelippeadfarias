<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

use InvalidArgumentException;

/**
 * Keeps guest data out of what alerts carry. An alert's context holds ids, codes, dates, numbers
 * and short texts; a key that names guest data (a name, a contact, a document, an address, a note
 * or a rate description) or a long free text is refused.
 */
final class GuestDataGuard
{
    /** Fragments of a normalised key (lowercase, letters and digits only) that name guest data. */
    private const FORBIDDEN = [
        'name', 'guest', 'mail', 'phone', 'mobile', 'document', 'passport', 'taxid', 'address', 'street', 'zip',
        'postcode', 'note', 'comment', 'message', 'description', 'remark',
    ];

    public const MAX_TEXT = 200;

    /**
     * @param array<int|string, mixed> $context
     *
     * @throws InvalidArgumentException naming the offending key
     */
    public static function check(array $context, string $path = 'context'): void
    {
        foreach ($context as $key => $value) {
            $here = $path . '.' . $key;
            if (is_string($key) && self::names(strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $key)))) {
                throw new InvalidArgumentException(sprintf('An alert cannot carry "%s": guest data stays out of alerts.', $here));
            }
            if (is_array($value)) {
                self::check($value, $here);
            } elseif (is_string($value) && mb_strlen($value) > self::MAX_TEXT) {
                throw new InvalidArgumentException(sprintf('"%s" is too long for an alert (free text stays out of alerts).', $here));
            } elseif (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException(sprintf('"%s" must be a number, a short text, a list or a map.', $here));
            }
        }
    }

    private static function names(string $key): bool
    {
        foreach (self::FORBIDDEN as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
