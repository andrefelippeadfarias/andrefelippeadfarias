<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use InvalidArgumentException;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Relations\RelationType;

/**
 * The relations tab (spec 05, 5.6): the order in which the rooms of the property are priced, one
 * card per relation with its sentence, the price bands, and the form to add or edit one.
 */
final class RelationsView
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $rules the draft's or the rules in force
     *
     * @return array<string, mixed>
     */
    public static function of(EditorPage $page, array $rules, string $add, string $editId, string $bandId, bool $mayEdit): array
    {
        $propertyId = $page->property?->id ?? '';
        $names = $page->roomNames();
        $relations = [];
        foreach ((array) ($rules['relations']['items'] ?? []) as $index => $item) {
            if (is_array($item) && ($item['property'] ?? '') === $propertyId) {
                $relations[] = [
                    'index' => $index,
                    'id' => (string) ($item['id'] ?? ''),
                    'label' => (string) (($item['label'] ?? '') !== '' ? $item['label'] : RelationText::describe($item, $names, $page->currency())),
                    'sentence' => RelationText::describe($item, $names, $page->currency()),
                    'kind' => ChoiceLabels::of('relations.items.*.type', (string) ($item['type'] ?? '')),
                    'enabled' => (bool) ($item['enabled'] ?? true),
                    'nights' => self::scope((array) ($item['scope'] ?? [])),
                ];
            }
        }
        $bands = [];
        foreach ((array) ($rules['price_bands']['items'] ?? []) as $index => $band) {
            if (is_array($band) && ($band['property'] ?? '') === $propertyId) {
                $bands[] = ['index' => $index, 'id' => (string) ($band['id'] ?? ''), 'room' => $names[$band['room'] ?? ''] ?? (string) ($band['room'] ?? ''), 'band' => $band];
            }
        }

        return [
            'order' => self::order($rules, $page),
            'relations' => $relations,
            'bands' => $bands,
            'types' => array_map(static fn (RelationType $t): array => ['value' => $t->value, 'label' => ChoiceLabels::of('relations.items.*.type', $t->value)], RelationType::cases()),
            'form' => $mayEdit ? self::form($page, $rules, $add, $editId) : null,
            'band_form' => $mayEdit && $bandId !== '' ? self::bandForm($page, $rules, $bandId) : null,
            'rooms' => count($names),
        ];
    }

    /**
     * Room names in the order the engine prices them (rooms that depend on none first).
     *
     * @param array<string, mixed> $rules
     *
     * @return list<string>
     */
    private static function order(array $rules, EditorPage $page): array
    {
        $names = $page->roomNames();
        try {
            $relations = array_values(array_filter(array_map(static function (mixed $item) use ($page): ?Relation {
                return is_array($item) && ($item['property'] ?? '') === ($page->property?->id ?? '') ? Relation::fromArray($item) : null;
            }, (array) ($rules['relations']['items'] ?? []))));
            $graph = new RelationGraph($relations, $page->rooms());
        } catch (InvalidConfig | InvalidArgumentException) {
            return [];
        }
        $targets = array_map(static fn (Relation $r): string => $r->target, $graph->ordered());
        $first = array_values(array_diff(array_keys($names), $targets));

        return array_map(static fn (string $code): string => $names[$code] ?? $code, array_values(array_unique([...$first, ...$targets])));
    }

    /**
     * @param array<string, mixed> $rules
     *
     * @return array<string, mixed>|null
     */
    private static function form(EditorPage $page, array $rules, string $add, string $editId): ?array
    {
        $item = null;
        foreach ((array) ($rules['relations']['items'] ?? []) as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $editId && $editId !== '') {
                $item = $candidate;
            }
        }
        $type = RelationType::tryFrom($item !== null ? (string) ($item['type'] ?? '') : $add);
        if ($type === null) {
            return null;
        }
        $schema = RelationForm::relationSchema($type);
        $values = $item ?? $schema->withDefaults(['target' => '', 'params' => []]);
        $ctx = new FieldContext($schema, null, [], $page->roomNames(), $page->currency(), FormErrors::take(), false, 'rel');

        return [
            'type' => $type->value,
            'type_label' => ChoiceLabels::of('relations.items.*.type', $type->value),
            'id' => $item !== null ? (string) $item['id'] : '',
            'nodes' => FieldView::nodes(RelationForm::typed($schema), $values, $ctx),
        ];
    }

    /**
     * @param array<string, mixed> $rules
     *
     * @return array<string, mixed>
     */
    private static function bandForm(EditorPage $page, array $rules, string $bandId): array
    {
        $band = null;
        foreach ((array) ($rules['price_bands']['items'] ?? []) as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $bandId) {
                $band = $candidate;
            }
        }
        $schema = RelationForm::bandSchema();
        $ctx = new FieldContext($schema, null, [], $page->roomNames(), $page->currency(), [], false, 'band');

        return ['id' => $band === null ? '' : $bandId, 'nodes' => FieldView::nodes(RelationForm::typed($schema), $band ?? $schema->withDefaults([]), $ctx)];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private static function scope(array $scope): string
    {
        $types = array_values(array_filter((array) ($scope['night_types'] ?? []), 'is_string'));
        $parts = [$types === [] ? __('Every night', 'ritmo-revenue') : implode(', ', array_map(static fn (string $t): string => ChoiceLabels::of('scope.night_types.*', $t), $types))];
        $max = $scope['max_days_out'] ?? null;
        if (is_int($max)) {
            /* translators: %d: number of days */
            $parts[] = sprintf(_n('up to %d day ahead', 'up to %d days ahead', $max, 'ritmo-revenue'), $max);
        }
        $except = count((array) ($scope['exclude_periods'] ?? []));
        if ($except > 0) {
            /* translators: %d: number of periods */
            $parts[] = sprintf(_n('except %d period', 'except %d periods', $except, 'ritmo-revenue'), $except);
        }

        return implode(', ', $parts);
    }
}
