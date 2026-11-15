<?php

declare(strict_types=1);

namespace Ritmo\Application\Ai;

use Ritmo\Application\Rules\PrivacyCheck;
use Ritmo\Application\Rules\SafetyFields;
use Ritmo\Domain\Ai\AiDraft;
use Ritmo\Domain\Ai\RuleCatalogue;
use Ritmo\Domain\Ai\RuleChange;

/**
 * How a draft reaches the editors, checked once more at that moment: an order becomes the fields
 * of the order form (the person gives the order there, or not); rule changes are put into the
 * person's rules draft, which the review of the rules checks and only a person saves. A change
 * of a safety field, or of a setting outside the catalogue, never goes through.
 */
final class DraftHandOff
{
    public function __construct(private readonly RuleCatalogue $catalogue)
    {
    }

    /**
     * @return array<string, mixed>|null the order form fields; null for a draft that is not an order
     */
    public function orderFields(AiDraft $draft): ?array
    {
        if ($draft->kind !== AiDraft::ORDER || $draft->order === null) {
            return null;
        }
        $fields = $draft->order->formFields();
        if (PrivacyCheck::problem($fields['note'], 500) !== null) {
            $fields['note'] = '';
        }

        return $fields;
    }

    /**
     * The rules the person's draft would hold with the changes, and the keys touched.
     *
     * @param array<string, mixed> $rules the rules the person's draft holds now (or the rules in force)
     *
     * @return array{rules: array<string, mixed>, touched: list<string>}|null null for a draft that is not a rule change
     *
     * @throws \InvalidArgumentException when a change may not go to the editor
     */
    public function ruleDraft(AiDraft $draft, array $rules): ?array
    {
        if ($draft->kind !== AiDraft::RULE_CHANGE) {
            return null;
        }
        $touched = [];
        foreach ($draft->ruleChanges as $change) {
            $this->assertAllowed($change);
            $rules = self::put($rules, explode('.', $change->path), $change->after);
            $touched[] = $change->key;
        }

        return ['rules' => $rules, 'touched' => array_values(array_unique($touched))];
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertAllowed(RuleChange $change): void
    {
        $field = $this->catalogue->fieldFor($change->path);
        if ($field === null || $field->key !== $change->key || SafetyFields::covers($change->key) || SafetyFields::covers($change->path)) {
            throw new \InvalidArgumentException(sprintf('The assistant may not change "%s".', $change->path));
        }
    }

    /**
     * @param array<int|string, mixed> $data
     * @param list<string>             $segments
     *
     * @return array<int|string, mixed>
     */
    private static function put(array $data, array $segments, mixed $value): array
    {
        $segment = array_shift($segments);
        $key = ctype_digit((string) $segment) ? (int) $segment : (string) $segment;
        $data[$key] = $segments === [] ? $value : self::put(is_array($data[$key] ?? null) ? $data[$key] : [], $segments, $value);

        return $data;
    }
}
