<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules;

/**
 * Free text typed by a person (an order note, a version note, a competitor's name) must never
 * carry guest data. This check refuses text that looks like an e-mail address, a phone number or
 * a Brazilian identity document (CPF or CNPJ), or that is longer than its field allows. Dates and
 * amounts are not mistaken for phone numbers.
 */
final class PrivacyCheck
{
    public const EMAIL = 'email';
    public const PHONE = 'phone';
    public const DOCUMENT = 'document';
    public const TOO_LONG = 'too_long';

    private const EMAIL_PATTERN = '/[^\s@]+@[^\s@]+\.[^\s@]+/u';
    private const DOCUMENT_PATTERNS = ['/\b\d{3}\.\d{3}\.\d{3}-\d{2}\b/', '/\b\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}\b/'];
    private const PHONE_PATTERN = '/\+?\(?\d(?:[\s.\-()]*\d){6,}/';
    private const NOT_PHONE_PATTERNS = ['/\b\d{4}-\d{2}-\d{2}\b/', '/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/', '/\b\d{1,3}(?:[.,]\d{3})+(?:[.,]\d{1,2})?\b/'];

    private function __construct()
    {
    }

    /**
     * The reason the text is refused (one of the constants), or null when it may be kept.
     */
    public static function problem(string $text, int $maxLength): ?string
    {
        if (mb_strlen($text) > $maxLength) {
            return self::TOO_LONG;
        }
        if (preg_match(self::EMAIL_PATTERN, $text) === 1) {
            return self::EMAIL;
        }
        foreach (self::DOCUMENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return self::DOCUMENT;
            }
        }
        $withoutDatesAndAmounts = (string) preg_replace(self::NOT_PHONE_PATTERNS, ' ', $text);

        return preg_match(self::PHONE_PATTERN, $withoutDatesAndAmounts) === 1 ? self::PHONE : null;
    }
}
