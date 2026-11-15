<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Ai;

use Ritmo\Domain\Ai\DraftContext;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Support\Days;

/**
 * A neutral property for the assistant's tests: two rooms with a base price, today 15 November
 * 2026 and the rules by default with one holiday period. Invented names only.
 */
final class AiFixtures
{
    public const TODAY = '2026-11-15';

    public const ROOMS = ['STD' => 500.0, 'SUITE' => 800.0];

    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $rules = RuleSet::defaults()->toArray();
        $rules['calendar']['holidays'][] = ['name' => 'Christmas', 'start' => '2026-12-24', 'end' => '2026-12-25', 'night_type' => 'holiday', 'min_stay' => null, 'floor' => null];

        return RuleSet::fromArray($rules)->toArray();
    }

    public static function context(string $request): DraftContext
    {
        return new DraftContext(Days::date(self::TODAY), self::ROOMS, $request, self::rules());
    }

    /**
     * A complete draft answer with the order fields given.
     *
     * @param array<string, mixed> $order
     * @param list<array{key: string, value: string}> $changes
     *
     * @return array<string, mixed>
     */
    public static function answer(string $kind, array $order = [], array $changes = [], array $questions = []): array
    {
        return [
            'kind' => $kind,
            'summary' => 'A draft.',
            'order' => $order + ['type' => 'follow_up', 'rooms' => [], 'from' => '', 'to' => '', 'weekdays' => [], 'floor_amount' => 0, 'change_percent' => 0, 'percentile' => 0, 'note' => ''],
            'rule_changes' => $changes,
            'questions' => $questions,
        ];
    }
}
