<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Ritmo\Application\Rules\FieldChange;
use Ritmo\Application\Rules\RulesRefused;
use Ritmo\Domain\Config\Schema;

/**
 * The versions tab (spec 05, 5.5.6): the versions with who saved them, when, from where, their
 * note and how many values they changed; and the comparison of two versions.
 */
final class VersionsView
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function of(EditorPage $page, int $pageNumber, ?int $from, ?int $to): array
    {
        $history = EditorServices::instance()->history();
        $versions = $history->page($pageNumber);
        $rows = array_map(static fn (array $r): array => [
            'version' => $r['version'],
            'at' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), $r['created_at']->getTimestamp()),
            'by' => self::person($r['created_by']),
            'source' => self::source($r['source'], $r['restored_from']),
            'note' => $r['note'],
            'changes' => $r['changes'],
            'in_force' => $r['in_force'],
        ], $versions['rows']);
        $compare = null;
        if ($from !== null && $to !== null) {
            try {
                $schema = Schema::ruleSet();
                $rules = $history->rules($to)->toArray();
                $compare = ['from' => $from, 'to' => $to, 'rows' => array_map(static fn (FieldChange $c): array => [
                    'label' => FieldPath::label($c->path, $schema, $rules),
                    'before' => ValueText::of($c->key, $c->before, $schema, $page->currency()),
                    'after' => ValueText::of($c->key, $c->after, $schema, $page->currency()),
                ], $history->compare($from, $to))];
            } catch (RulesRefused $refused) {
                $compare = ['from' => $from, 'to' => $to, 'rows' => [], 'error' => EditorText::refusal($refused)];
            }
        }

        return [
            'rows' => $rows,
            'total' => $versions['total'],
            'page_number' => $pageNumber,
            'compare' => $compare,
            // The changes of a version open in place through the read-only compare route.
            'compare_rest' => ['url' => rest_url('ritmo/v1/rules/compare'), 'nonce' => wp_create_nonce('wp_rest'), 'property' => $page->property?->id ?? ''],
        ];
    }

    public static function person(int $userId): string
    {
        if ($userId === 0) {
            return __('The plugin', 'ritmo-revenue');
        }
        $user = get_userdata($userId);

        return $user === false ? __('A person no longer on this site', 'ritmo-revenue') : (string) $user->display_name;
    }

    private static function source(string $source, ?int $restoredFrom): string
    {
        return match ($source) {
            /* translators: %d: the version restored */
            'restore' => $restoredFrom === null ? __('Restored', 'ritmo-revenue') : sprintf(__('Restored from version %d', 'ritmo-revenue'), $restoredFrom),
            'import' => __('Preset file', 'ritmo-revenue'),
            'wizard' => __('Setup', 'ritmo-revenue'),
            'editor' => __('Rules screen', 'ritmo-revenue'),
            default => '—',
        };
    }
}
