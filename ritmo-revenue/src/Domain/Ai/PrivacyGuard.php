<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

/**
 * Keeps guest data out of everything the assistant sends and keeps.
 *
 * - scrub(): drops every key of a data tree that names guest data (a name, a contact, a
 *   document, an address, a note or a free-text message) before a prompt is built;
 * - problem() and assertSendable(): refuse a text that looks like an e-mail address, a phone
 *   number or a Brazilian identity document (CPF, CNPJ). Nothing is sent then: the person is told
 *   why. Dates, amounts, percentages and booking ids (up to nine digits) are not phone numbers.
 */
final class PrivacyGuard
{
    public const EMAIL = 'email';
    public const PHONE = 'phone';
    public const DOCUMENT = 'document';

    /** Fragments of a normalised key (lowercase letters and digits only) that name guest data. */
    private const FORBIDDEN_KEYS = [
        'name', 'guest', 'mail', 'phone', 'mobile', 'whatsapp', 'contact', 'document', 'passport', 'cpf', 'cnpj',
        'taxid', 'address', 'street', 'zip', 'postcode', 'note', 'comment', 'message', 'remark', 'birth', 'firstname',
        'lastname', 'invoicee', 'customer',
    ];

    private const EMAIL_PATTERN = '/[^\s@<>"]+@[^\s@<>"]+\.[^\s@<>"]+/u';

    private const DOCUMENT_PATTERNS = [
        '/(?<!\d)\d{3}\.?\d{3}\.?\d{3}-\d{2}(?!\d)/',
        '/(?<!\d)\d{2}\.?\d{3}\.?\d{3}\/\d{4}-?\d{2}(?!\d)/',
    ];

    /** Values that look like numbers but are not phone numbers: dates, grouped and decimal amounts, percentages. */
    private const NOT_PHONE_PATTERNS = [
        '/(?<!\d)\d{4}-\d{2}-\d{2}(?!\d)/',
        '/(?<!\d)\d{1,2}\/\d{1,2}\/\d{2,4}(?!\d)/',
        '/(?<!\d)\d{1,3}(?:[.,]\d{3})+(?:[.,]\d{1,2})?(?!\d)/',
        '/(?<!\d)\d+[.,]\d{1,2}(?!\d)/',
        '/(?<!\d)\d+(?:[.,]\d+)?\s?%/',
    ];

    /** A run of digits with separators (or a "+") from this many digits on is a phone number. */
    private const PHONE_DIGITS = 8;

    /** A bare run of digits from this length on is a phone number (booking ids are shorter). */
    private const BARE_DIGITS = 10;

    private function __construct()
    {
    }

    /**
     * The data tree without any key that names guest data, at any depth.
     *
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    public static function scrub(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::namesGuestData($key)) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::scrub($value) : $value;
        }

        return array_is_list($data) ? array_values($clean) : $clean;
    }

    public static function namesGuestData(string $key): bool
    {
        $normal = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $key));
        foreach (self::FORBIDDEN_KEYS as $fragment) {
            if (str_contains($normal, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the text looks like it carries (one of the constants), or null when it may be sent.
     */
    public static function problem(string $text): ?string
    {
        if (preg_match(self::EMAIL_PATTERN, $text) === 1) {
            return self::EMAIL;
        }
        foreach (self::DOCUMENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return self::DOCUMENT;
            }
        }
        $rest = (string) preg_replace(self::NOT_PHONE_PATTERNS, ' ', $text);
        if (preg_match_all('/\+?\(?\d[\d\s().\/-]*\d/', $rest, $matches) > 0) {
            foreach ($matches[0] as $candidate) {
                $digits = strlen((string) preg_replace('/\D/', '', $candidate));
                $separated = preg_match('/[\s().\/+-]/', $candidate) === 1;
                if (($separated && $digits >= self::PHONE_DIGITS) || $digits >= self::BARE_DIGITS) {
                    return self::PHONE;
                }
            }
        }

        return null;
    }

    /**
     * The first problem of any string in a data tree (keys included), or null.
     *
     * @param array<int|string, mixed> $data
     */
    public static function problemIn(array $data): ?string
    {
        foreach ($data as $key => $value) {
            $problem = (is_string($key) ? self::problem($key) : null)
                ?? (is_string($value) ? self::problem($value) : null)
                ?? (is_array($value) ? self::problemIn($value) : null);
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * @throws AiStopped (privacy) when a text looks like guest data
     */
    public static function assertSendable(string ...$texts): void
    {
        foreach ($texts as $text) {
            $problem = self::problem($text);
            if ($problem !== null) {
                throw new AiStopped(AiStopped::PRIVACY, 'Nothing was sent: the text looks like it carries guest data (an e-mail, a phone number or a document number). Refer to a booking by its id.', ['kind' => $problem]);
            }
        }
    }
}
