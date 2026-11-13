<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules\Calendar;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * A country's list of national holidays shipped with the plugin (presets/holidays/<CC>.json,
 * format "ritmo-revenue-holidays" version 1): each holiday is a fixed date ("MM-DD") or a number
 * of days from Easter Sunday, national or an optional day off ("ponto facultativo"). The file may
 * carry the dates of some years as published by the official source ("checked"), which a test
 * compares with the rules. Read without any network access.
 */
final class HolidayList
{
    public const FORMAT = 'ritmo-revenue-holidays';

    /**
     * @param array<string, string>                                                                          $names    language => country name ("en" required)
     * @param list<int>                                                                                      $daysOff  weekdays without work (0 Monday ... 6 Sunday)
     * @param list<array{id: string, fixed: string|null, easter: int|null, optional: bool, names: array<string, string>}> $holidays
     */
    private function __construct(
        public readonly string $country,
        public readonly array $names,
        public readonly array $daysOff,
        public readonly bool $bridges,
        public readonly array $holidays,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException naming what does not read
     */
    public static function fromArray(array $data): self
    {
        if (($data['format'] ?? null) !== self::FORMAT || ($data['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Not a holiday list of this plugin.');
        }
        $country = $data['country'] ?? null;
        if (!is_string($country) || preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new InvalidArgumentException('The country is a two-letter code.');
        }
        $daysOff = $data['days_off'] ?? null;
        if (!is_array($daysOff) || array_filter($daysOff, static fn (mixed $d): bool => !is_int($d) || $d < 0 || $d > 6) !== []) {
            throw new InvalidArgumentException('Days off are weekdays from 0 (Monday) to 6 (Sunday).');
        }
        $holidays = [];
        foreach ((array) ($data['holidays'] ?? []) as $index => $holiday) {
            $holidays[] = self::holiday(is_array($holiday) ? $holiday : [], $index);
        }
        $ids = array_column($holidays, 'id');
        if ($holidays === [] || count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('A holiday list has holidays with unique ids.');
        }

        return new self($country, self::names($data['names'] ?? null), array_values(array_unique($daysOff)), (bool) ($data['bridges'] ?? false), $holidays);
    }

    /**
     * The holidays of a year, by date.
     *
     * @return list<array{id: string, date: DateTimeImmutable, optional: bool, names: array<string, string>}>
     */
    public function datesIn(int $year): array
    {
        $easter = Easter::sunday($year);
        $dates = [];
        foreach ($this->holidays as $holiday) {
            $date = $holiday['fixed'] !== null ? Days::date(sprintf('%04d-%s', $year, $holiday['fixed'])) : Days::add($easter, (int) $holiday['easter']);
            $dates[] = ['id' => $holiday['id'], 'date' => $date, 'optional' => $holiday['optional'], 'names' => $holiday['names']];
        }
        usort($dates, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $dates;
    }

    /**
     * The name in a language ("pt_BR"), else in English.
     *
     * @param array<string, string> $names
     */
    public static function name(array $names, string $locale): string
    {
        return $names[$locale] ?? $names[substr($locale, 0, 2)] ?? $names['en'];
    }

    /**
     * @param array<string, mixed> $holiday
     *
     * @return array{id: string, fixed: string|null, easter: int|null, optional: bool, names: array<string, string>}
     */
    private static function holiday(array $holiday, int|string $index): array
    {
        $id = $holiday['id'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9_]{0,47}$/', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('Holiday %s has no valid id.', (string) $index));
        }
        $rule = (array) ($holiday['rule'] ?? []);
        $fixed = $rule['fixed'] ?? null;
        $easter = $rule['easter'] ?? null;
        $fixedOk = is_string($fixed) && preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $fixed) === 1 && Days::isValid('2024-' . $fixed);
        $easterOk = is_int($easter) && $easter >= -100 && $easter <= 100;
        if ($fixedOk === $easterOk || count($rule) !== 1) {
            throw new InvalidArgumentException(sprintf('Holiday "%s" needs one rule: a fixed date or days from Easter.', $id));
        }

        $kind = $holiday['kind'] ?? 'national';
        if (!in_array($kind, ['national', 'optional'], true)) {
            throw new InvalidArgumentException(sprintf('Holiday "%s" is national or optional.', $id));
        }

        return ['id' => $id, 'fixed' => $fixedOk ? $fixed : null, 'easter' => $easterOk ? $easter : null, 'optional' => $kind === 'optional', 'names' => self::names($holiday['names'] ?? null)];
    }

    /**
     * @return array<string, string>
     */
    private static function names(mixed $names): array
    {
        if (!is_array($names) || !is_string($names['en'] ?? null) || $names['en'] === '') {
            throw new InvalidArgumentException('Every name has an English text.');
        }
        foreach ($names as $locale => $name) {
            if (!is_string($locale) || preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $locale) !== 1 || !is_string($name) || $name === '' || mb_strlen($name) > 80) {
                throw new InvalidArgumentException('Names are short texts keyed by language.');
            }
        }

        return $names;
    }
}
