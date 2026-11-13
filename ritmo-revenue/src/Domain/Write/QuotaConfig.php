<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write;

use InvalidArgumentException;
use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * Limits of the pricing system's recalculation (section "quota"). They are the provider's rules,
 * so the neutral defaults are the provider's published limits: 3 per listing in a rolling 24 h
 * and 10 per account per rolling minute; after a 429 the whole account waits a minute. The
 * configuration can only make them stricter (task rule 9(f)): fewer recalculations, a longer
 * window, a longer wait; never more than the provider allows nor a window under 24 h.
 * Reference: cota_recalculo.py (LIMITE_QUARTO, JANELA_QUARTO, LIMITE_CONTA, ESPERA_429_CONTA,
 * FOLGA_FUTURO).
 */
final class QuotaConfig
{
    public const ROOT = 'quota';

    public function __construct(
        public readonly int $perListing = 3,
        public readonly int $windowHours = 24,
        public readonly int $perAccountPerMinute = 10,
        public readonly int $accountWaitAfter429Seconds = 60,
        public readonly int $futureSlackSeconds = 300,
        public readonly int $failureCooldownMinutes = 60,
    ) {
        foreach (self::fields() as $field) {
            $value = match ($field->key) {
                'quota.per_listing' => $perListing,
                'quota.window_hours' => $windowHours,
                'quota.per_account_per_minute' => $perAccountPerMinute,
                'quota.account_wait_after_429_seconds' => $accountWaitAfter429Seconds,
                'quota.future_slack_seconds' => $futureSlackSeconds,
                'quota.failure_cooldown_minutes' => $failureCooldownMinutes,
                default => null,
            };
            if ($value !== null && ($value < $field->min || $value > $field->max)) {
                throw new InvalidArgumentException(sprintf('%s must be between %d and %d: the configuration can only make the provider\'s limits stricter.', $field->key, $field->min, $field->max));
            }
        }
    }

    /**
     * @param array<string, mixed> $data the "quota" section; missing keys take the provider's limits
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(self::fields());
        $d = $reader->open(self::ROOT, $data);
        $values = [
            $reader->int($d['per_listing'], 'quota.per_listing'),
            $reader->int($d['window_hours'], 'quota.window_hours'),
            $reader->int($d['per_account_per_minute'], 'quota.per_account_per_minute'),
            $reader->int($d['account_wait_after_429_seconds'], 'quota.account_wait_after_429_seconds'),
            $reader->int($d['future_slack_seconds'], 'quota.future_slack_seconds'),
            $reader->int($d['failure_cooldown_minutes'], 'quota.failure_cooldown_minutes'),
        ];
        $reader->finish(self::ROOT);

        return new self(...$values);
    }

    /**
     * @return list<Field>
     */
    public static function fields(): array
    {
        return [
            new Field('quota', FieldType::Group, 'Recalculation limits', 'How often the pricing system may be asked to recalculate a listing. Failed attempts count too.'),
            new Field('quota.per_listing', FieldType::Integer, 'Per listing', 'Recalculations of one listing inside the rolling window (the provider allows at most 3).', 3, min: 1, max: 3),
            new Field('quota.window_hours', FieldType::Integer, 'Rolling window', 'Hours of the rolling window of a listing (not the calendar day; at least the provider\'s 24).', 24, min: 24, max: 48),
            new Field('quota.per_account_per_minute', FieldType::Integer, 'Per account per minute', 'Recalculations of the whole account in any rolling minute (the provider allows at most 10).', 10, min: 1, max: 10),
            new Field('quota.account_wait_after_429_seconds', FieldType::Integer, 'Wait after a 429', 'After a rate-limit answer on any listing, no listing is recalculated for this long (at least the provider\'s minute).', 60, min: 60, max: 600),
            new Field('quota.future_slack_seconds', FieldType::Integer, 'Clock slack', 'Attempts recorded up to this far after now still count (a clock ahead).', 300, min: 0, max: 600),
            new Field('quota.failure_cooldown_minutes', FieldType::Integer, 'Cooldown after a failure', 'A listing whose recalculation failed is not tried again in the same run, and at least this long for a run started by hand or a resume.', 60, min: 0, max: 1440),
        ];
    }
}
