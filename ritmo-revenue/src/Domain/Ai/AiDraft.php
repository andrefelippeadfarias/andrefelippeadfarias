<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

use InvalidArgumentException;

/**
 * What the assistant made of a request, checked by the plugin: an order for the order form, or
 * rule changes for the person's rules draft. It is only a draft: it changes nothing until a person
 * opens it, reviews it and saves it through the editor (which checks it again).
 */
final class AiDraft
{
    public const ORDER = 'order';
    public const RULE_CHANGE = 'rule_change';

    /**
     * @param list<RuleChange> $ruleChanges
     * @param list<string>     $questions
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $summary,
        public readonly ?OrderDraft $order = null,
        public readonly array $ruleChanges = [],
        public readonly array $questions = [],
    ) {
        if ($kind === self::ORDER && $order === null) {
            throw new InvalidArgumentException('An order draft carries its order.');
        }
        if ($kind === self::RULE_CHANGE && $ruleChanges === []) {
            throw new InvalidArgumentException('A rule change draft carries at least one change.');
        }
        if (!in_array($kind, [self::ORDER, self::RULE_CHANGE], true)) {
            throw new InvalidArgumentException('A draft is an order or a rule change.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'summary' => $this->summary,
            'order' => $this->order?->toArray(),
            'rule_changes' => array_map(static fn (RuleChange $c): array => $c->toArray(), $this->ruleChanges),
            'questions' => $this->questions,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException when the stored draft does not read
     */
    public static function fromArray(array $data): self
    {
        $changes = [];
        foreach (is_array($data['rule_changes'] ?? null) ? $data['rule_changes'] : [] as $row) {
            $change = is_array($row) ? RuleChange::fromArray($row) : null;
            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return new self(
            (string) ($data['kind'] ?? ''),
            (string) ($data['summary'] ?? ''),
            is_array($data['order'] ?? null) ? OrderDraft::fromArray($data['order']) : null,
            $changes,
            array_values(array_filter(is_array($data['questions'] ?? null) ? $data['questions'] : [], 'is_string')),
        );
    }
}
