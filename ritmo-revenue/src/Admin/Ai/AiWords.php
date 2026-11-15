<?php

declare(strict_types=1);

namespace Ritmo\Admin\Ai;

use Ritmo\Domain\Ai\AiEffort;
use Ritmo\Domain\Ai\AiModel;
use Ritmo\Domain\Ai\AiPurpose;
use Ritmo\Domain\Ai\AiStopped;

/**
 * Every text of the assistant's screens and answers in the reader's language: why it stopped,
 * why a draft was refused, the models, the efforts and the purposes.
 */
final class AiWords
{
    private function __construct()
    {
    }

    /**
     * The sentence for a stop, and its details as sentences.
     *
     * @return array{message: string, details: list<string>}
     */
    public static function stopped(AiStopped $stopped): array
    {
        $details = [];
        if ($stopped->reason === AiStopped::DRAFT_REFUSED) {
            foreach (is_array($stopped->details['problems'] ?? null) ? $stopped->details['problems'] : [] as $problem) {
                $details[] = self::problem((string) $problem);
            }
        }
        if ($stopped->reason === AiStopped::UNKNOWN_NUMBER) {
            /* translators: %s: numbers the assistant wrote, separated by commas */
            $details[] = sprintf(__('Numbers not in the data: %s', 'ritmo-revenue'), implode(', ', array_map('strval', (array) ($stopped->details['numbers'] ?? []))));
        }
        if ($stopped->reason === AiStopped::REFUSAL && is_string($stopped->details['category'] ?? null)) {
            /* translators: %s: a category given by the model, such as cyber */
            $details[] = sprintf(__('Category given by the model: %s', 'ritmo-revenue'), $stopped->details['category']);
        }
        if ($stopped->reason === AiStopped::NOT_SUPPORTED) {
            foreach ((array) ($stopped->details['questions'] ?? []) as $question) {
                $details[] = (string) $question;
            }
        }
        if ($stopped->reason === AiStopped::PROVIDER) {
            $details[] = $stopped->getMessage();
        }

        return ['message' => self::reason($stopped->reason, $stopped), 'details' => $details];
    }

    public static function reason(string $reason, ?AiStopped $stopped = null): string
    {
        return match ($reason) {
            AiStopped::DISABLED => __('The assistant is off. An administrator can turn it on in its settings.', 'ritmo-revenue'),
            AiStopped::NO_KEY => __('No Claude API key is saved. An administrator can add one in the assistant settings.', 'ritmo-revenue'),
            AiStopped::BUDGET => __('The assistant reached its monthly spending cap and stays off until next month, or until the cap is raised.', 'ritmo-revenue'),
            AiStopped::MODEL_UNAVAILABLE => __('The saved key cannot use the chosen model. Choose another model in the assistant settings.', 'ritmo-revenue'),
            AiStopped::PRIVACY => __('Nothing was sent: the text looks like it carries guest data (an e-mail, a phone number or a document number). Refer to a booking by its id.', 'ritmo-revenue'),
            AiStopped::REFUSAL => __('The model declined to answer this request. Nothing was kept.', 'ritmo-revenue'),
            AiStopped::MAX_TOKENS => __('The answer was cut before its end. Nothing was kept; try a shorter request.', 'ritmo-revenue'),
            AiStopped::INVALID_OUTPUT => __('The assistant answered in a shape the plugin does not accept. Nothing was kept.', 'ritmo-revenue'),
            AiStopped::UNKNOWN_NUMBER => __('The assistant cited a number that is not in the plugin\'s data, so the text was thrown away. Try again.', 'ritmo-revenue'),
            AiStopped::DRAFT_REFUSED => __('The plugin did not accept the assistant\'s draft. Nothing was kept.', 'ritmo-revenue'),
            AiStopped::NOT_SUPPORTED => is_string($stopped?->details['summary'] ?? null) && $stopped->details['summary'] !== ''
                /* translators: %s: what the assistant said about the request */
                ? sprintf(__('The assistant cannot turn this request into a draft: %s', 'ritmo-revenue'), $stopped->details['summary'])
                : __('The assistant cannot turn this request into a draft.', 'ritmo-revenue'),
            default => __('Claude could not answer now. Nothing was changed.', 'ritmo-revenue'),
        };
    }

    /**
     * Why a draft was refused, from its code ("unknown_room:LOFT").
     */
    public static function problem(string $problem): string
    {
        [$code, $subject] = array_pad(explode(':', $problem, 2), 2, '');

        return match ($code) {
            'no_room' => __('No room was chosen.', 'ritmo-revenue'),
            /* translators: %s: a room code */
            'unknown_room' => sprintf(__('%s is not a room of this property.', 'ritmo-revenue'), $subject),
            'amount_and_percent' => __('An order takes an amount or a percentage, not both.', 'ritmo-revenue'),
            'amount_range' => __('The amount is out of range.', 'ritmo-revenue'),
            'amount_not_written' => __('The amount is not one you wrote.', 'ritmo-revenue'),
            'amount_type' => __('Only a minimum price or a market target order takes an amount.', 'ritmo-revenue'),
            'percent_range' => __('The percentage is out of range (from -50 to 200).', 'ritmo-revenue'),
            'percent_not_written' => __('The percentage is not one you wrote.', 'ritmo-revenue'),
            'percent_type' => __('A relative change becomes a minimum price order.', 'ritmo-revenue'),
            /* translators: %s: a room code */
            'no_base' => sprintf(__('Room %s has no base price to compute from.', 'ritmo-revenue'), $subject),
            'floor_without_amount' => __('A minimum price order needs an amount or a percentage.', 'ritmo-revenue'),
            'percentile_range' => __('A market percentile goes from 1 to 110, on a market target order.', 'ritmo-revenue'),
            'percentile_not_written' => __('The percentile is not one you wrote.', 'ritmo-revenue'),
            'dates' => __('The first and the last night must be dates.', 'ritmo-revenue'),
            'nights_range' => __('The nights go from today to at most a year ahead, the first before the last.', 'ritmo-revenue'),
            'weekday' => __('A day of the week that does not exist was given.', 'ritmo-revenue'),
            'no_night' => __('No night between the first and the last falls on those days of the week.', 'ritmo-revenue'),
            'changes_count' => __('A rule change draft changes from 1 to 10 settings.', 'ritmo-revenue'),
            /* translators: %s: the dotted name of a setting */
            'setting_denied' => sprintf(__('%s is not a setting the assistant may change (safety limits, modes, writing and quotas never are).', 'ritmo-revenue'), $subject),
            /* translators: %s: the dotted name of a setting */
            'setting_twice' => sprintf(__('%s is changed twice.', 'ritmo-revenue'), $subject),
            /* translators: %s: the dotted name of a setting */
            'setting_missing' => sprintf(__('%s does not exist in the rules in force.', 'ritmo-revenue'), $subject),
            /* translators: %s: the dotted name of a setting */
            'setting_value' => sprintf(__('%s does not take that value.', 'ritmo-revenue'), $subject),
            /* translators: %s: the dotted name of a setting */
            'setting_not_written' => sprintf(__('The value of %s is not one you wrote.', 'ritmo-revenue'), $subject),
            'no_change' => __('Nothing would change: the rules already hold these values.', 'ritmo-revenue'),
            /* translators: %s: the dotted name of a setting */
            'rules_invalid' => sprintf(__('The rules would not be valid at %s.', 'ritmo-revenue'), $subject),
            default => __('The draft does not fit the rules of the plugin.', 'ritmo-revenue'),
        };
    }

    public static function model(string $id): string
    {
        return match ($id) {
            AiModel::OPUS => __('Claude Opus 5.5 (most capable)', 'ritmo-revenue'),
            AiModel::SONNET => __('Claude Sonnet 5.5 (balanced)', 'ritmo-revenue'),
            AiModel::HAIKU => __('Claude Haiku 5.5 (fastest, lowest cost)', 'ritmo-revenue'),
            default => $id,
        };
    }

    public static function effort(AiEffort $effort): string
    {
        return match ($effort) {
            AiEffort::Low => __('Low: faster and cheaper', 'ritmo-revenue'),
            AiEffort::Medium => __('Medium (recommended)', 'ritmo-revenue'),
            AiEffort::High => __('High: more careful, costs more', 'ritmo-revenue'),
        };
    }

    public static function purpose(string $purpose): string
    {
        return match (AiPurpose::tryFrom($purpose)) {
            AiPurpose::WeeklyReport => __('Weekly report text', 'ritmo-revenue'),
            AiPurpose::RunReport => __('Run report text', 'ritmo-revenue'),
            AiPurpose::AlertExplanation => __('Alert explanation', 'ritmo-revenue'),
            AiPurpose::RequestDraft => __('Draft from a request', 'ritmo-revenue'),
            null => __('Other', 'ritmo-revenue'),
        };
    }

    public static function kind(string $kind): string
    {
        return $kind === 'order' ? __('Owner order draft', 'ritmo-revenue') : __('Rule change draft', 'ritmo-revenue');
    }

    /**
     * An estimated amount in US dollars (four decimals for the cost of one answer).
     */
    public static function dollars(float $amount, int $decimals = 2): string
    {
        /* translators: %s: an amount in US dollars */
        return sprintf(__('US$ %s', 'ritmo-revenue'), number_format_i18n($amount, $decimals));
    }
}
