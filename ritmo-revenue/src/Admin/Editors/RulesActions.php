<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use InvalidArgumentException;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Notice;
use Ritmo\Application\Rules\RulesRefused;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\PresetLoader;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Config\ValidationError;

/**
 * The form actions of the Rules screen (spec 05, appendix B). Each stages a change into the
 * person's draft or, for "Save", makes a new version of the rules; none writes a price or calls
 * an external system. Capability and nonce are checked by the dispatcher before a handler runs;
 * the use case checks again what needs ritmo_manage_safety.
 */
final class RulesActions
{
    private function __construct()
    {
    }

    /**
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        $r = Capability::MANAGE_RULES;
        $page = Menu::RULES;

        return [
            new FormAction('ritmo_rules_draft_update', $r, $page, self::draftUpdate(...)),
            new FormAction('ritmo_rules_draft_discard', $r, $page, self::discard(...)),
            new FormAction('ritmo_rules_save', $r, $page, self::save(...)),
            new FormAction('ritmo_rules_keep_theirs', $r, $page, self::keepTheirs(...)),
            new FormAction('ritmo_rules_restore', $r, $page, self::restore(...), true, 'version'),
            new FormAction('ritmo_rules_export', Capability::VIEW, $page, self::export(...), false, '', true),
            new FormAction('ritmo_rules_import', $r, $page, self::import(...)),
            new FormAction('ritmo_relation_stage', $r, $page, RelationActions::relation(...)),
            new FormAction('ritmo_band_stage', $r, $page, RelationActions::band(...)),
            new FormAction('ritmo_calendar_stage', $r, $page, CalendarActions::calendar(...)),
            new FormAction('ritmo_holidays_stage', $r, $page, CalendarActions::holidays(...)),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function draftUpdate(Input $form): array
    {
        $scopeId = $form->text('scope', 32);
        $scope = in_array($scopeId, [RulesLayout::SAFETY, RulesLayout::CALENDAR, RulesLayout::RELATIONS], true) ? FormScope::tab($scopeId) : FormScope::section(array_key_exists($scopeId, RulesLayout::SECTIONS) ? $scopeId : 'goals');
        $userId = get_current_user_id();
        $drafts = EditorServices::instance()->drafts();
        $schema = Schema::ruleSet();
        $result = RuleFormReader::read($schema, $scope, $drafts->rules($userId), RequestFields::posted('f'));
        $errors = [];
        foreach ($result->errors as $error) {
            $errors[$error['path']] = EditorText::unreadable($error['code']);
        }
        $drafts->stage($userId, $result->rules, $result->touched);
        FormErrors::flash($errors);
        if ($errors !== []) {
            Notice::flash(Notice::WARNING, __('Some values could not be read and were not kept; the others are in your draft (not saved yet).', 'ritmo-revenue'), array_map(
                static fn (string $path, string $text): string => FieldPath::label($path, $schema, $result->rules) . ': ' . $text,
                array_keys($errors),
                $errors
            ));
        } else {
            Notice::flash(Notice::SUCCESS, __('Change kept in your draft (not saved yet).', 'ritmo-revenue'));
        }

        return $scope->tab === RulesLayout::GENERAL ? ['tab' => RulesLayout::GENERAL, 'section' => $scope->section] : ['tab' => $scope->tab];
    }

    /**
     * @return array<string, string>
     */
    private static function discard(Input $form): array
    {
        EditorServices::instance()->drafts()->discard(get_current_user_id());
        Notice::flash(Notice::SUCCESS, __('Your unsaved changes were discarded.', 'ritmo-revenue'));

        return [];
    }

    /**
     * @return array<string, string>
     */
    private static function save(Input $form): array
    {
        $userId = get_current_user_id();
        $services = EditorServices::instance();
        $draft = $services->drafts()->current($userId);
        if ($draft === null) {
            Notice::flash(Notice::ERROR, EditorText::refusal(new RulesRefused(RulesRefused::NO_DRAFT)));

            return ['tab' => 'review'];
        }
        try {
            $saved = $services->editor()->save($draft->rules, $draft->baseVersion, $draft->baseHash, $userId, $form->text('note', 190), $form->text('confirmation', 190), Capability::can(Capability::MANAGE_SAFETY), $draft->source, $draft->restoredFrom);
        } catch (RulesRefused $refused) {
            Notice::flash(Notice::ERROR, EditorText::refusal($refused));

            return ['tab' => 'review'];
        } catch (InvalidConfig $invalid) {
            $schema = Schema::ruleSet();
            Notice::flash(Notice::ERROR, __('The rules were not saved: correct these values first.', 'ritmo-revenue'), array_map(static fn (ValidationError $e): string => EditorText::validation($e, $schema, $draft->rules), $invalid->errors()));

            return ['tab' => 'review'];
        }
        $services->drafts()->discard($userId);
        if ($saved->unchanged) {
            Notice::flash(Notice::SUCCESS, __('Nothing changed: the rules are the ones in force.', 'ritmo-revenue'));

            return ['tab' => 'versions'];
        }
        $details = [];
        if ($saved->closedApprovals > 0) {
            $details[] = sprintf(
                /* translators: %d: number of plans closed */
                _n('%d plan waiting for approval was closed; the next run proposes a new one with these rules.', '%d plans waiting for approval were closed; the next run proposes new ones with these rules.', $saved->closedApprovals, 'ritmo-revenue'),
                $saved->closedApprovals
            );
        }
        Notice::flash(Notice::SUCCESS, sprintf(
            /* translators: 1: the new version, 2: the version before it */
            __('Version %1$d saved. It applies from the next run; runs already started keep version %2$d.', 'ritmo-revenue'),
            $saved->version,
            $saved->previous ?? 0
        ), $details);

        return ['tab' => 'versions'];
    }

    /**
     * Drops one conflicting value from the draft: the value of the newer version is kept.
     *
     * @return array<string, string>
     */
    private static function keepTheirs(Input $form): array
    {
        $userId = get_current_user_id();
        $drafts = EditorServices::instance()->drafts();
        $draft = $drafts->current($userId);
        $path = $form->text('path', 190);
        if ($draft === null || preg_match('/^[a-z0-9_.*-]{1,190}$/', $path) !== 1) {
            return ['tab' => 'review'];
        }
        $inForce = $drafts->inForce();
        $rules = self::put($draft->rules, explode('.', $path), self::at($inForce, explode('.', $path)));
        $drafts->stage($userId, $rules, [$path], $draft->source, $draft->restoredFrom);
        Notice::flash(Notice::SUCCESS, __('The value in force was kept.', 'ritmo-revenue'));

        return ['tab' => 'review'];
    }

    /**
     * @return array<string, string>
     */
    private static function restore(Input $form): array
    {
        $version = $form->int('version', 0, 1);
        try {
            $rules = EditorServices::instance()->history()->rules($version)->toArray();
        } catch (RulesRefused $refused) {
            Notice::flash(Notice::ERROR, EditorText::refusal($refused));

            return ['tab' => 'versions'];
        }
        $userId = get_current_user_id();
        $drafts = EditorServices::instance()->drafts();
        $drafts->discard($userId);
        $drafts->stage($userId, $rules, [], 'restore', $version);
        /* translators: %d: the version restored */
        Notice::flash(Notice::SUCCESS, sprintf(__('Version %d is in your draft. Review it and save: it becomes a new version, the history is kept.', 'ritmo-revenue'), $version));

        return ['tab' => 'review'];
    }

    /**
     * @return array<string, string>
     */
    private static function export(Input $form): array
    {
        PresetFile::send();

        return [];
    }

    /**
     * @return array<string, string>
     */
    private static function import(Input $form): array
    {
        try {
            $preset = (new PresetLoader())->fromJson(PresetFile::upload('preset_file'));
        } catch (InvalidArgumentException $refused) {
            Notice::flash(Notice::ERROR, $refused->getMessage());

            return ['tab' => 'versions'];
        } catch (InvalidConfig $invalid) {
            Notice::flash(Notice::ERROR, __('This preset file has values the plugin does not accept; nothing was kept.', 'ritmo-revenue'), array_slice(array_map(static fn (ValidationError $e): string => EditorText::problem($e), $invalid->errors()), 0, 20));

            return ['tab' => 'versions'];
        }
        $userId = get_current_user_id();
        $drafts = EditorServices::instance()->drafts();
        $drafts->discard($userId);
        $drafts->stage($userId, $preset->rules->toArray(), [], 'import');
        Notice::flash(Notice::SUCCESS, __('The rules of the file are in your draft. Review them and save. Properties and rooms of a file are created in Settings › Setup.', 'ritmo-revenue'));

        return ['tab' => 'review'];
    }

    /**
     * @param array<int|string, mixed> $data
     * @param list<string>             $segments
     */
    public static function at(array $data, array $segments): mixed
    {
        foreach ($segments as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }

    /**
     * @param array<int|string, mixed> $data
     * @param list<string>             $segments
     *
     * @return array<int|string, mixed>
     */
    public static function put(array $data, array $segments, mixed $value): array
    {
        $segment = array_shift($segments);
        if ($segment === null) {
            return $data;
        }
        $data[$segment] = $segments === [] ? $value : self::put(is_array($data[$segment] ?? null) ? $data[$segment] : [], $segments, $value);

        return $data;
    }
}
