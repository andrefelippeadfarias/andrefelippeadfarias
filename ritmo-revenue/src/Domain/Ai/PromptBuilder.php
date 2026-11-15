<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

use InvalidArgumentException;

/**
 * Builds the question of each purpose: the fixed system blocks (PromptLibrary), then one user
 * message with the locale and the data between delimiters (DataBlock). Every text of the data is
 * checked for guest data first: a request that would carry an e-mail, a phone number or a document
 * number is never built (AiStopped, privacy).
 */
final class PromptBuilder
{
    public const REQUEST_MAX = 1000;

    public const LOCALE_PATTERN = '/^[a-z]{2,3}(_[A-Z]{2})?$/';

    /** Languages named in the user message for the locales the plugin ships or is likely to meet. */
    private const LANGUAGES = [
        'en' => 'English',
        'pt_BR' => 'Portuguese (Brazil)',
        'pt_PT' => 'Portuguese (Portugal)',
        'pt' => 'Portuguese',
        'es' => 'Spanish',
        'fr' => 'French',
        'it' => 'Italian',
        'de' => 'German',
    ];

    public function __construct(private readonly ?RuleCatalogue $catalogue = null)
    {
    }

    /**
     * The text of a weekly or a run report from facts the plugin computed and formatted.
     *
     * @param list<array{label: string, value: string|int|float}> $facts
     * @param array<string, string|int|float>                     $about what the report covers (period, property, run kind)
     *
     * @throws AiStopped (privacy)
     */
    public function report(AiPurpose $purpose, array $about, array $facts, string $locale): AiRequest
    {
        if (!in_array($purpose, [AiPurpose::WeeklyReport, AiPurpose::RunReport], true)) {
            throw new InvalidArgumentException('A report prompt is for the weekly report or a run report.');
        }

        return new AiRequest($purpose, PromptLibrary::system($purpose), $this->user($locale, ['about' => $about, 'facts' => $facts]));
    }

    /**
     * The explanation of one alert.
     *
     * @param array<string, mixed> $alert code, severity, room, night, summary (the alert's sentence) and context
     *
     * @throws AiStopped (privacy)
     */
    public function alert(array $alert, string $locale): AiRequest
    {
        return new AiRequest(AiPurpose::AlertExplanation, PromptLibrary::system(AiPurpose::AlertExplanation), $this->user($locale, ['alert' => $alert]), OutputSchemas::alert());
    }

    /**
     * A draft from the person's request.
     *
     * @param array<string, mixed> $data today, last_night, currency, rooms (code, label, base) and periods (path, label, first, last)
     *
     * @throws AiStopped (privacy) for a request with guest data
     * @throws InvalidArgumentException for an empty or too long request
     */
    public function request(string $text, array $data, string $locale): AiRequest
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::REQUEST_MAX) {
            throw new InvalidArgumentException(sprintf('A request has from 1 to %d characters.', self::REQUEST_MAX));
        }
        PrivacyGuard::assertRequestSendable($text, PrivacyGuard::labels($data));
        $catalogue = ($this->catalogue ?? RuleCatalogue::forRules())->text();

        return new AiRequest(
            AiPurpose::RequestDraft,
            PromptLibrary::system(AiPurpose::RequestDraft, $catalogue),
            $this->user($locale, $data) . "\n" . DataBlock::wrap('request', ['text' => $text]),
            OutputSchemas::draft()
        );
    }

    public static function language(string $locale): string
    {
        return self::LANGUAGES[$locale] ?? self::LANGUAGES[substr($locale, 0, 2)] ?? $locale;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws AiStopped (privacy)
     */
    private function user(string $locale, array $data): string
    {
        if (preg_match(self::LOCALE_PATTERN, $locale) !== 1) {
            $locale = 'en';
        }
        $data = PrivacyGuard::scrub($data);
        $problem = PrivacyGuard::problemIn($data);
        if ($problem !== null) {
            throw new AiStopped(AiStopped::PRIVACY, 'Nothing was sent: the data looks like it carries guest data (an e-mail, a phone number or a document number).', ['kind' => $problem]);
        }

        return sprintf("Locale: %s. Write in %s.\n", $locale, self::language($locale)) . DataBlock::wrap('data', $data);
    }
}
