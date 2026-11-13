<?php

declare(strict_types=1);

namespace Ritmo\Rest;

use InvalidArgumentException;

/**
 * Strict reading of the inputs that change what the plugin may write: a decision on a plan
 * waiting for approval, the entries it names and the position of the emergency switch. Anything
 * malformed is refused (the routes answer 400): a refusal typed wrong never becomes an approval,
 * and a request without a clear position never releases the switch. Pure: no WordPress call.
 */
final class OperationInput
{
    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    private const TRUE_WORDS = ['1', 'true'];

    private const FALSE_WORDS = ['0', 'false'];

    private function __construct()
    {
    }

    /**
     * @throws InvalidArgumentException unless the value is exactly "approve" or "reject"
     */
    public static function decision(mixed $value): string
    {
        if ($value !== self::APPROVE && $value !== self::REJECT) {
            throw new InvalidArgumentException('The decision must be "approve" or "reject".');
        }

        return $value;
    }

    /**
     * The entries named by an approval: null when none is named (the whole request), else a
     * non-empty list of non-empty keys.
     *
     * @return list<string>|null
     *
     * @throws InvalidArgumentException when the value is not a list of non-empty keys
     */
    public static function keys(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            throw new InvalidArgumentException('The entries must be a non-empty list of entry keys.');
        }
        foreach ($value as $key) {
            if (!is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException('Every entry key must be a non-empty text.');
            }
        }

        return array_values(array_map('trim', $value));
    }

    /**
     * The amounts typed to confirm listing changes: an object of entry key => amount (digits, an
     * optional dot or comma and two decimals); none when absent.
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException when the value is not such an object
     */
    public static function confirmations(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || count($value) > 10) {
            throw new InvalidArgumentException('The confirmations must be an object of entry key => amount.');
        }
        $out = [];
        foreach ($value as $key => $amount) {
            if (!is_string($key) || !is_scalar($amount) || preg_match('/^\d{1,9}([.,]\d{1,2})?$/', trim((string) $amount)) !== 1) {
                throw new InvalidArgumentException('Every confirmation is an amount typed for a listing entry.');
            }
            $out[$key] = trim((string) $amount);
        }

        return $out;
    }

    /**
     * The position asked for the emergency switch: true engages, false releases.
     *
     * @throws InvalidArgumentException unless the value is a boolean or one of "1", "true", "0", "false"
     */
    public static function engaged(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || (is_string($value) && in_array(strtolower(trim($value)), self::TRUE_WORDS, true))) {
            return true;
        }
        if ($value === 0 || (is_string($value) && in_array(strtolower(trim($value)), self::FALSE_WORDS, true))) {
            return false;
        }

        throw new InvalidArgumentException('Say whether the switch is engaged: true or false.');
    }

    /**
     * The position asked on the screen's form: "engage" or "release", nothing else.
     *
     * @throws InvalidArgumentException for any other value
     */
    public static function switchPosition(string $value): bool
    {
        return match ($value) {
            'engage' => true,
            'release' => false,
            default => throw new InvalidArgumentException('Choose to engage or to release the emergency switch.'),
        };
    }
}
