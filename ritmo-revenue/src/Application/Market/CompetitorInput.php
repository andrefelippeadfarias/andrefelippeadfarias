<?php

declare(strict_types=1);

namespace Ritmo\Application\Market;

use Ritmo\Application\Rules\PrivacyCheck;
use Ritmo\Domain\Market\Series\ComparableFactor;
use Ritmo\Domain\Market\Series\CompetitorProfile;

/**
 * Reads and checks the fields of a competitor typed in the market screen. The reference is kept
 * as plain text: it is never fetched nor linked.
 */
final class CompetitorInput
{
    public const NAME_MAX = 120;

    public const TEXT_MAX = 190;

    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $input name, code, reference, room_label, tags (list or comma text), factor, factor_source, rating, is_own, active
     *
     * @return array{code: string, name: string, reference: string, room_label: string, tags: list<string>, factor: float|null, factor_source: string, rating: float|null, is_own: bool, active: bool}
     *
     * @throws MarketRefused naming the first field that does not read
     */
    public static function read(array $input): array
    {
        $name = self::text($input, 'name', self::NAME_MAX);
        if ($name === '') {
            throw new MarketRefused(MarketRefused::REQUIRED, 'name');
        }
        $code = strtolower(self::text($input, 'code', 64));
        $code = $code === '' ? self::slug($name) : $code;
        if (preg_match(CompetitorProfile::CODE_PATTERN, $code) !== 1) {
            throw new MarketRefused(MarketRefused::BAD_CODE, 'code');
        }

        return [
            'code' => $code,
            'name' => $name,
            'reference' => self::text($input, 'reference', self::TEXT_MAX),
            'room_label' => self::text($input, 'room_label', self::TEXT_MAX),
            'tags' => self::tags($input['tags'] ?? []),
            'factor' => self::number($input, 'factor', 0.0, 10.0, true, MarketRefused::BAD_FACTOR),
            'factor_source' => in_array($input['factor_source'] ?? '', [ComparableFactor::MEASURED, ComparableFactor::ESTIMATED], true) ? (string) $input['factor_source'] : '',
            'rating' => self::number($input, 'rating', 0.0, 10.0, false, MarketRefused::BAD_RATING),
            'is_own' => (bool) ($input['is_own'] ?? false),
            'active' => (bool) ($input['active'] ?? true),
        ];
    }

    /**
     * A code from a name: lowercase letters and digits joined by dashes ("Pousada Sol" → "pousada-sol").
     */
    public static function slug(string $name): string
    {
        $ascii = strtr(mb_strtolower(trim($name)), self::ACCENTS);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');

        return substr($slug === '' ? 'competitor' : $slug, 0, 64);
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function text(array $input, string $field, int $max): string
    {
        $value = $input[$field] ?? '';
        $text = is_string($value) ? trim($value) : '';
        $problem = PrivacyCheck::problem($text, $max);
        if ($problem === PrivacyCheck::TOO_LONG) {
            throw new MarketRefused(MarketRefused::TOO_LONG, $field, ['max' => $max]);
        }
        if ($problem !== null) {
            throw new MarketRefused(MarketRefused::PRIVACY, $field);
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private static function tags(mixed $value): array
    {
        $tags = is_array($value) ? $value : explode(',', is_string($value) ? $value : '');
        $clean = [];
        foreach ($tags as $tag) {
            $tag = strtolower(trim((string) $tag));
            if ($tag === '') {
                continue;
            }
            if (preg_match(CompetitorProfile::TAG_PATTERN, $tag) !== 1) {
                throw new MarketRefused(MarketRefused::BAD_TAG, 'tags');
            }
            $clean[$tag] = true;
        }

        return array_keys($clean);
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function number(array $input, string $field, float $min, float $max, bool $exclusiveMin, string $reason): ?float
    {
        $value = trim((string) ($input[$field] ?? ''));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{1,3}(?:[.,]\d{1,3})?$/', $value) !== 1) {
            throw new MarketRefused($reason, $field);
        }
        $number = (float) str_replace(',', '.', $value);
        if ($number > $max || $number < $min || ($exclusiveMin && $number <= $min)) {
            throw new MarketRefused($reason, $field);
        }

        return $number;
    }
}
