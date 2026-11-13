<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Ritmo\Application\Rules\PrivacyCheck;
use Ritmo\Application\Rules\RulesRefused;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Relations\RelationValidator;

/**
 * The sentences of the rules and orders screens for what went wrong: a refusal, a value that
 * does not read, a validation error with the field it is about. Never a dotted path, never an
 * internal code.
 */
final class EditorText
{
    private function __construct()
    {
    }

    public static function refusal(RulesRefused $refused): string
    {
        return match ($refused->reason) {
            RulesRefused::SAFETY_NOT_ALLOWED => __('Only a person allowed to change safety limits can change these. Nothing was saved.', 'ritmo-revenue'),
            RulesRefused::RELEASE_NOT_ALLOWED => __('Only a person allowed to change safety limits can give this permission. Nothing was saved.', 'ritmo-revenue'),
            /* translators: %s: the exact text to type */
            RulesRefused::CONFIRMATION => sprintf(__('The confirmation does not match: type %s to confirm. Nothing was saved.', 'ritmo-revenue'), (string) ($refused->params['expected'] ?? '')),
            RulesRefused::CONFLICT => __('Someone saved a different value for the same setting after you started. Review the conflicts below, keep their value or change yours, then save again.', 'ritmo-revenue'),
            RulesRefused::NOTE => self::privacy((string) ($refused->params['problem'] ?? '')),
            RulesRefused::NO_DRAFT => __('There is no unsaved change any more (it was saved, discarded or is older than 7 days).', 'ritmo-revenue'),
            RulesRefused::UNKNOWN_VERSION => __('That version of the rules cannot be read.', 'ritmo-revenue'),
            default => __('That order or frozen night does not exist or is no longer active.', 'ritmo-revenue'),
        };
    }

    public static function privacy(string $problem): string
    {
        return $problem === PrivacyCheck::TOO_LONG
            ? __('The text is too long.', 'ritmo-revenue')
            : __('Do not write guest names, e-mails, phone numbers or document numbers here. Refer to a booking by its id.', 'ritmo-revenue');
    }

    /**
     * A value of a form that does not read (codes of FieldWidget::read).
     */
    public static function unreadable(string $code): string
    {
        return match ($code) {
            'whole_number' => __('Type a whole number.', 'ritmo-revenue'),
            'number' => __('Type a number, such as 60 or 7.5.', 'ritmo-revenue'),
            'date' => __('Choose a date.', 'ritmo-revenue'),
            'choice' => __('Choose one of the options.', 'ritmo-revenue'),
            default => __('This value cannot be read.', 'ritmo-revenue'),
        };
    }

    /**
     * A validation error as "<field label path>: <what is wrong>".
     *
     * @param array<string, mixed> $rules
     */
    public static function validation(ValidationError $error, Schema $schema, array $rules): string
    {
        return FieldPath::label($error->path, $schema, $rules) . ': ' . self::problem($error);
    }

    public static function problem(ValidationError $error): string
    {
        return match ($error->code) {
            ValidationError::REQUIRED => __('this is required.', 'ritmo-revenue'),
            ValidationError::RANGE => __('this is outside the allowed range.', 'ritmo-revenue'),
            ValidationError::TYPE, ValidationError::FORMAT => __('this does not have the right form.', 'ritmo-revenue'),
            ValidationError::CHOICE => __('choose one of the options.', 'ritmo-revenue'),
            ValidationError::UNIQUE => __('a value is repeated.', 'ritmo-revenue'),
            ValidationError::LOCKED => __('this is fixed by the product.', 'ritmo-revenue'),
            ValidationError::ORDER => __('the values are in the wrong order.', 'ritmo-revenue'),
            ValidationError::REFERENCE, RelationValidator::UNKNOWN_ROOM, RelationValidator::UNKNOWN_PROPERTY => __('it names a room or a property that does not exist.', 'ritmo-revenue'),
            RelationValidator::SELF_REFERENCE => __('a room cannot be compared with itself.', 'ritmo-revenue'),
            RelationValidator::TARGET_IN_SET => __('the room adjusted cannot be in the group it is compared with.', 'ritmo-revenue'),
            RelationValidator::BAD_LINK => __('it cites a relation that does not exist or is of the wrong kind.', 'ritmo-revenue'),
            RelationValidator::CYCLE => __('the relations make room prices depend on each other in a loop.', 'ritmo-revenue'),
            'ladder.series_unknown' => __('it cites a market price series that is not built.', 'ritmo-revenue'),
            'ladder.percentile_not_measured' => __('it cites a point of the market that is not kept.', 'ritmo-revenue'),
            default => __('this value is not accepted.', 'ritmo-revenue'),
        };
    }
}
