<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * The typed reader of one request's fields (the query string of a screen, or the body of a form
 * after its capability and nonce were checked). Every value is unslashed and sanitised; a value
 * that does not fit is refused (forms) or replaced by the default (filters), never truncated.
 */
final class Input
{
    /**
     * @param array<string, mixed> $fields $_GET or $_POST as WordPress hands them (slashed)
     */
    public function __construct(private readonly array $fields)
    {
    }

    public static function query(): self
    {
        // Screens read their filters from the query string; filters never change state.
        return new self($_GET); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    public static function form(): self
    {
        // The caller (ScreenActions::handle) has checked the capability and the nonce.
        return new self($_POST); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->fields);
    }

    /**
     * @throws InvalidArgumentException when the text is longer than $max
     */
    public function text(string $name, int $max = 190): string
    {
        $value = $this->raw($name);
        $text = is_string($value) ? trim(sanitize_text_field($value)) : '';
        if (mb_strlen($text) > $max) {
            /* translators: %d: the longest text allowed */
            throw new InvalidArgumentException(sprintf(__('A text here has at most %d characters.', 'ritmo-revenue'), $max));
        }

        return $text;
    }

    /**
     * @throws InvalidArgumentException when the text is longer than $max
     */
    public function multiline(string $name, int $max = 1000): string
    {
        $value = $this->raw($name);
        $text = is_string($value) ? trim(sanitize_textarea_field($value)) : '';
        if (mb_strlen($text) > $max) {
            /* translators: %d: the longest text allowed */
            throw new InvalidArgumentException(sprintf(__('A text here has at most %d characters.', 'ritmo-revenue'), $max));
        }

        return $text;
    }

    /** A secret exactly as typed apart from surrounding spaces (never sanitised into another value). */
    public function secret(string $name): string
    {
        $value = $this->raw($name);

        return is_string($value) ? trim($value) : '';
    }

    public function int(string $name, int $default = 0, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $value = $this->raw($name);
        if (!is_string($value) || preg_match('/^-?\d{1,9}$/', trim($value)) !== 1) {
            return $default;
        }

        return max($min, min($max, (int) trim($value)));
    }

    /** A night "YYYY-MM-DD", or null when absent or not a real date. */
    public function date(string $name): ?DateTimeImmutable
    {
        $value = $this->raw($name);

        return is_string($value) && Days::isValid(trim($value)) ? Days::date(trim($value)) : null;
    }

    /**
     * @param list<string> $allowed
     */
    public function choice(string $name, array $allowed, string $default): string
    {
        $value = $this->raw($name);

        return is_string($value) && in_array(trim($value), $allowed, true) ? trim($value) : $default;
    }

    public function bool(string $name): bool
    {
        $value = $this->raw($name);

        return is_string($value) && in_array(trim($value), ['1', 'on', 'yes'], true);
    }

    /**
     * Ticked values of "name[]", each sanitised and matching $pattern; at most $max.
     *
     * @return list<string>
     */
    public function list(string $name, string $pattern, int $max = 5000): array
    {
        $value = $this->raw($name);
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach (array_slice($value, 0, $max) as $item) {
            $item = is_string($item) ? trim(sanitize_text_field($item)) : '';
            if ($item !== '' && preg_match($pattern, $item) === 1) {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Rows of a repeated fieldset ("name[0][field]"): each field sanitised as text; at most $max rows.
     *
     * @param list<string> $fields
     *
     * @return list<array<string, string>>
     */
    public function rows(string $name, array $fields, int $max = 100): array
    {
        $value = $this->raw($name);
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach (array_slice(array_values($value), 0, $max) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = [];
            foreach ($fields as $field) {
                $clean[$field] = is_string($row[$field] ?? null) ? trim(sanitize_text_field($row[$field])) : '';
            }
            $rows[] = $clean;
        }

        return $rows;
    }

    private function raw(string $name): mixed
    {
        $value = $this->fields[$name] ?? null;
        if (is_string($value)) {
            return wp_unslash($value);
        }

        return is_array($value) ? wp_unslash($value) : null;
    }
}
