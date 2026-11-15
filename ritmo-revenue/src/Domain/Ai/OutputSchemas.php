<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

/**
 * The JSON schemas of the structured answers (output_config.format). Every object closes its
 * properties (additionalProperties false) and requires all of them; the schemas carry no range
 * or length keyword and no recursion (the API refuses them): ranges, lengths and meanings are
 * checked in PHP afterwards (JsonShape, then DraftReader). The draft schema allows only the
 * fields of a draft: nothing in it can name a mode, a key or a write.
 */
final class OutputSchemas
{
    public const ORDER_TYPES = ['floor', 'target', 'fixed_price', 'restriction', 'follow_up'];

    public const DRAFT_KINDS = ['order', 'rule_change', 'not_supported'];

    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function alert(): array
    {
        return self::object(['explanation' => ['type' => 'string'], 'action' => ['type' => 'string']]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function draft(): array
    {
        return self::object([
            'kind' => ['type' => 'string', 'enum' => self::DRAFT_KINDS],
            'summary' => ['type' => 'string'],
            'order' => self::object([
                'type' => ['type' => 'string', 'enum' => self::ORDER_TYPES],
                'rooms' => ['type' => 'array', 'items' => ['type' => 'string']],
                'from' => ['type' => 'string'],
                'to' => ['type' => 'string'],
                'weekdays' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'floor_amount' => ['type' => 'number'],
                'change_percent' => ['type' => 'number'],
                'percentile' => ['type' => 'integer'],
                'note' => ['type' => 'string'],
            ]),
            'rule_changes' => ['type' => 'array', 'items' => self::object(['key' => ['type' => 'string'], 'value' => ['type' => 'string']])],
            'questions' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     *
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
}
