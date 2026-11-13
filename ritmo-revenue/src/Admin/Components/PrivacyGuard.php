<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

/**
 * Refuses free text that looks like guest data: an e-mail address, a phone number, a Brazilian
 * CPF or CNPJ. Used on every text a person types (to-do items, notes). A booking is referred to
 * by its id: a run of up to nine digits without separators is not taken for a phone number.
 */
final class PrivacyGuard
{
    private const PATTERNS = [
        'email' => '/[^\s@]+@[^\s@]+\.[^\s@]+/u',
        'cpf' => '/\b\d{3}\.\d{3}\.\d{3}-\d{2}\b/',
        'cnpj' => '/\b\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}\b/',
        'phone' => '/\+?\(?\d[\d\s().-]{5,}\d/',
    ];

    /** Phone-like runs with at least this many digits are refused when they have a separator or "+". */
    private const PHONE_DIGITS = 7;

    /** A run of digits alone is a phone number from this length on (booking ids are shorter). */
    private const BARE_DIGITS = 10;

    private function __construct()
    {
    }

    /**
     * The refusal to show, or null when the text is fine.
     */
    public static function check(string $text): ?string
    {
        return self::looksPrivate($text)
            ? __('Do not write guest names, e-mails, phone numbers or document numbers here. Refer to a booking by its id.', 'ritmo-revenue')
            : null;
    }

    public static function looksPrivate(string $text): bool
    {
        foreach (['email', 'cpf', 'cnpj'] as $kind) {
            if (preg_match(self::PATTERNS[$kind], $text) === 1) {
                return true;
            }
        }
        if (preg_match_all(self::PATTERNS['phone'], $text, $matches) > 0) {
            foreach ($matches[0] as $candidate) {
                $digits = strlen((string) preg_replace('/\D/', '', $candidate));
                $separated = preg_match('/[\s().+-]/', $candidate) === 1;
                if (($separated && $digits >= self::PHONE_DIGITS) || $digits >= self::BARE_DIGITS) {
                    return true;
                }
            }
        }

        return false;
    }
}
