<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Log;

/**
 * Cleans a log entry before it is kept, as the last line of defence behind the rule that
 * callers never log credentials or guest data:
 *
 * - values under keys that name a credential become "[redacted]";
 * - values under keys that name guest data, and whole request, response or booking bodies,
 *   become "[omitted]";
 * - inside any text: registered secrets, bearer tokens, credentials in query strings and
 *   e-mail addresses are masked;
 * - long texts and big or deep arrays are cut, so an entry stays small.
 */
final class LogSanitizer
{
    public const REDACTED = '[redacted]';
    public const OMITTED = '[omitted]';

    private const SECRET_KEY = '/api[_-]?key|token|secret|passw|authori[sz]ation|credential|cookie|signature|private[_-]?key/i';
    private const PERSONAL_KEY = '/guest|(first|last|full|sur)[_-]?name|e[_-]?mail|phone|mobile|address|street|post[_-]?code|zip|passport|document|birth|comment|notes?$/i';
    private const BODY_KEY = '/^(raw|payload|(request|response)?[_-]?body|request|response|bookings?|reservations?)$/i';
    private const QUERY_CREDENTIAL = '/(?<![A-Za-z0-9_-])((?:[A-Za-z0-9_-]*(?:api[_-]?key|token|secret|passw(?:or)?d|signature)|key)=)[^&\s#"\';,]+/i';
    private const BEARER = '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i';
    private const EMAIL = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';
    private const MIN_SECRET_LENGTH = 6;
    private const MAX_TEXT = 500;
    private const MAX_ITEMS = 50;
    private const MAX_DEPTH = 4;

    /** @var list<string> */
    private array $secrets = [];

    /**
     * Makes a secret value unprintable: wherever it appears in a message or a context, it is
     * masked. Values shorter than six characters are ignored (they would mask ordinary text).
     */
    public function registerSecret(string $secret): void
    {
        if (strlen($secret) >= self::MIN_SECRET_LENGTH && !in_array($secret, $this->secrets, true)) {
            $this->secrets[] = $secret;
        }
    }

    public function message(string $message): string
    {
        return $this->text($message);
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed> only scalars, null and arrays of those
     */
    public function context(array $context): array
    {
        return $this->items($context, 1);
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return array<array-key, mixed>
     */
    private function items(array $items, int $depth): array
    {
        $clean = [];
        foreach ($items as $key => $value) {
            if (count($clean) >= self::MAX_ITEMS) {
                $clean['...'] = sprintf('%d more', count($items) - self::MAX_ITEMS);
                break;
            }
            $clean[$key] = $this->value((string) $key, $value, $depth);
        }

        return $clean;
    }

    private function value(string $key, mixed $value, int $depth): mixed
    {
        if (preg_match(self::SECRET_KEY, $key) === 1) {
            return self::REDACTED;
        }
        if (preg_match(self::PERSONAL_KEY, $key) === 1 || (preg_match(self::BODY_KEY, $key) === 1 && !is_int($value) && !is_bool($value))) {
            return self::OMITTED;
        }

        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : null,
            is_string($value) => $this->text($value),
            is_array($value) => $depth >= self::MAX_DEPTH ? self::OMITTED : $this->items($value, $depth + 1),
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof \BackedEnum => $value->value,
            default => sprintf('[%s]', get_debug_type($value)),
        };
    }

    private function text(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, self::REDACTED, $text);
        }
        $text = (string) preg_replace(self::QUERY_CREDENTIAL, '$1' . self::REDACTED, $text);
        $text = (string) preg_replace(self::BEARER, '$1 ' . self::REDACTED, $text);
        $text = (string) preg_replace(self::EMAIL, self::OMITTED, $text);
        if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT) {
            $text = mb_substr($text, 0, self::MAX_TEXT, 'UTF-8') . '...';
        }

        return $this->validUtf8($text);
    }

    /**
     * The text with every byte sequence that is not UTF-8 replaced, so the entry can always
     * be encoded and stored. Uses only functions that exist on every PHP installation.
     */
    private function validUtf8(string $text): string
    {
        if (preg_match('//u', $text) === 1) {
            return $text;
        }

        return htmlspecialchars_decode(htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_NOQUOTES);
    }
}
