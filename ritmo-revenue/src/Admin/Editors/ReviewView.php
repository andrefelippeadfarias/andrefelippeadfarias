<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use OutOfBoundsException;
use Ritmo\Application\Rules\FieldChange;
use Ritmo\Application\Rules\RuleDraft;
use Ritmo\Application\Rules\RuleDiff;
use Ritmo\Application\Rules\RuleMerge;
use Ritmo\Application\Rules\SafetyFields;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Config\ValidationError;

/**
 * What the review tab shows before a version is saved (spec 05, 5.5.1 step 2): one row per value
 * that changes (label path, before, after, default), every error with a link to its field, the
 * safety values and what to type, the conflicts with a newer version, and the plans waiting for
 * approval that saving would close.
 */
final class ReviewView
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function of(RuleDraft $draft, EditorPage $page, bool $maySafety): array
    {
        $services = EditorServices::instance();
        $schema = Schema::ruleSet();
        $inForce = $services->drafts()->inForce();
        $currency = $page->currency();
        $changes = RuleDiff::between($inForce, $draft->rules, $schema);
        $row = static fn (FieldChange $c): array => [
            'label' => FieldPath::label($c->path, $schema, $c->kind === FieldChange::REMOVED ? $inForce : $draft->rules),
            'before' => $c->kind === FieldChange::ADDED ? __('(new)', 'ritmo-revenue') : ValueText::of($c->key, $c->before, $schema, $currency),
            'after' => $c->kind === FieldChange::REMOVED ? __('(removed)', 'ritmo-revenue') : ValueText::of($c->key, $c->after, $schema, $currency),
            'default' => $c->key !== null && $schema->has($c->key) && $c->kind === FieldChange::CHANGED ? ValueText::of($c->key, $schema->field($c->key)->default, $schema, $currency) : '',
            'safety' => SafetyFields::covers($c->key),
        ];
        $safety = SafetyFields::of($changes);

        return [
            'rows' => array_map($row, $changes),
            'errors' => array_map(static fn (ValidationError $e): array => [
                'text' => EditorText::validation($e, $schema, $draft->rules),
                'url' => self::fieldUrl($page, $e->path),
            ], $services->checks()->errors($draft->rules)),
            'safety' => $safety === [] ? null : ['rows' => array_map($row, $safety), 'expected' => SafetyFields::confirmation($safety), 'allowed' => $maySafety],
            'conflicts' => self::conflicts($draft, $schema, $inForce, $currency),
            'waiting' => $services->editor()->waitingNights(),
            'property_names' => array_column(array_map(static fn ($p): array => ['id' => $p->id, 'name' => $p->name], $page->properties), 'name', 'id'),
            'source' => $draft->source,
            'restored_from' => $draft->restoredFrom,
            'version' => EditorServices::instance()->services()->ruleSets()->currentVersion() ?? 0,
        ];
    }

    /**
     * The URL of the tab and section that edit a path, with the field's anchor.
     */
    public static function fieldUrl(EditorPage $page, string $path): string
    {
        $key = Schema::ruleSet()->keyForPath($path) ?? $path;
        try {
            $placement = RulesLayout::place($key);
        } catch (OutOfBoundsException) {
            return $page->url(['tab' => 'review']);
        }
        $args = ['tab' => $placement->tab];
        if ($placement->tab === RulesLayout::GENERAL) {
            $args['section'] = $placement->section;
        }

        return $page->url($args) . '#' . FieldPath::anchor($path);
    }

    /**
     * @param array<string, mixed> $inForce
     *
     * @return list<array{label: string, path: string, mine: string, theirs: string}>
     */
    private static function conflicts(RuleDraft $draft, Schema $schema, array $inForce, string $currency): array
    {
        $services = EditorServices::instance()->services();
        if ($draft->baseVersion === ($services->ruleSets()->currentVersion() ?? 0)) {
            return [];
        }
        try {
            $base = $services->ruleSets()->find($draft->baseVersion);
        } catch (InvalidConfig) {
            $base = null;
        }
        if ($base === null) {
            return [];
        }

        return array_map(static fn (array $c): array => [
            'label' => FieldPath::label($c['path'], $schema, $inForce),
            'path' => $c['path'],
            'mine' => ValueText::of($c['key'], $c['mine'], $schema, $currency),
            'theirs' => ValueText::of($c['key'], $c['theirs'], $schema, $currency),
        ], RuleMerge::merge($base->toArray(), $draft->rules, $inForce, $schema)['conflicts']);
    }
}
