<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use InvalidArgumentException;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Notice;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Relations\Config\RelationsConfig;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationType;
use Ritmo\Domain\Relations\RelationValidator;

/**
 * Stages a room relation or a price band into the person's rules draft (spec 05, 5.6): add, edit,
 * duplicate, turn on or off, delete. The relations are checked at once against the rooms of the
 * site, so a loop or an unknown room is refused next to the form and nothing is kept.
 */
final class RelationActions
{
    private function __construct()
    {
    }

    /**
     * @return array<string, string>
     */
    public static function relation(Input $form): array
    {
        $op = $form->choice('op', ['add', 'edit', 'toggle', 'delete', 'duplicate'], 'add');
        $id = $form->text('id', 64);
        $back = ['tab' => RulesLayout::RELATIONS];
        $rules = EditorServices::instance()->drafts()->rules(get_current_user_id());
        $items = array_values(array_filter((array) ($rules['relations']['items'] ?? []), 'is_array'));
        $index = self::indexOf($items, $id);
        if ($op !== 'add' && $index === null) {
            Notice::flash(Notice::ERROR, __('That relation is not in the rules any more.', 'ritmo-revenue'));

            return $back;
        }
        $taken = array_map(static fn (array $i): string => (string) ($i['id'] ?? ''), $items);
        switch ($op) {
            case 'toggle':
                $items[$index]['enabled'] = !($items[$index]['enabled'] ?? true);
                break;
            case 'delete':
                array_splice($items, $index, 1);
                break;
            case 'duplicate':
                $copy = $items[$index];
                $copy['id'] = RelationForm::newId('rel', (string) $copy['target'], $taken);
                $copy['label'] = mb_substr(trim((string) ($copy['label'] ?? '') . ' ' . __('(copy)', 'ritmo-revenue')), 0, 120);
                $copy['enabled'] = false;
                array_splice($items, $index + 1, 0, [$copy]);
                break;
            default:
                $item = self::posted($form, $op === 'edit' ? $items[$index] : null, $taken);
                if ($item === null) {
                    return $back + ($op === 'edit' ? ['edit' => $id] : ['add' => $form->text('type', 32)]);
                }
                if ($op === 'edit') {
                    $items[$index] = $item;
                } else {
                    $items[] = $item;
                }
        }

        return self::stage($rules, $items, null, $back);
    }

    /**
     * @return array<string, string>
     */
    public static function band(Input $form): array
    {
        $id = $form->text('id', 64);
        $back = ['tab' => RulesLayout::RELATIONS];
        $rules = EditorServices::instance()->drafts()->rules(get_current_user_id());
        $bands = array_values(array_filter((array) ($rules['price_bands']['items'] ?? []), 'is_array'));
        $index = self::indexOf($bands, $id);
        if ($form->choice('op', ['save', 'delete'], 'save') === 'delete') {
            if ($index !== null) {
                array_splice($bands, $index, 1);
            }

            return self::stage($rules, null, $bands, $back);
        }
        $schema = RelationForm::bandSchema();
        $read = RuleFormReader::read($schema, null, $index === null ? $schema->withDefaults([]) : $bands[$index], RequestFields::posted('band'));
        if ($read->errors !== []) {
            Notice::flash(Notice::ERROR, __('Some values could not be read; nothing was kept.', 'ritmo-revenue'));

            return $back + ['band' => $id === '' ? 'new' : $id];
        }
        $band = $read->rules;
        $band['property'] = $form->text('property', 64);
        $band['id'] = $index === null ? RelationForm::newId('band', (string) ($band['room'] ?? 'room'), array_map(static fn (array $b): string => (string) ($b['id'] ?? ''), $bands)) : $id;
        if ($index === null) {
            $bands[] = $band;
        } else {
            $bands[$index] = $band;
        }

        return self::stage($rules, null, $bands, $back);
    }

    /**
     * The relation typed in the form, or null (with a notice) when it does not read.
     *
     * @param array<string, mixed>|null $current
     * @param list<string>              $taken
     *
     * @return array<string, mixed>|null
     */
    private static function posted(Input $form, ?array $current, array $taken): ?array
    {
        $type = RelationType::tryFrom($current === null ? $form->text('type', 32) : (string) ($current['type'] ?? ''));
        if ($type === null) {
            Notice::flash(Notice::ERROR, __('Choose the kind of relation first.', 'ritmo-revenue'));

            return null;
        }
        $schema = RelationForm::relationSchema($type);
        $read = RuleFormReader::read($schema, null, $current ?? $schema->withDefaults(['params' => []]), RequestFields::posted('rel'));
        $item = $read->rules;
        $item['type'] = $type->value;
        $item['property'] = $form->text('property', 64);
        $item['id'] = $current === null ? RelationForm::newId('rel', (string) ($item['target'] ?? 'room'), $taken) : (string) $current['id'];
        $errors = [];
        foreach ($read->errors as $error) {
            $errors[$error['path']] = EditorText::unreadable($error['code']);
        }
        if ($errors === []) {
            try {
                Relation::fromArray($item);
            } catch (InvalidConfig $invalid) {
                foreach ($invalid->errors() as $error) {
                    $errors[$error->path] = EditorText::problem($error);
                }
            } catch (InvalidArgumentException) {
                $errors['target'] = EditorText::unreadable('');
            }
        }
        if ($errors !== []) {
            FormErrors::flash($errors);
            Notice::flash(Notice::ERROR, __('The relation was not kept: correct the values marked below.', 'ritmo-revenue'), array_map(static fn (string $p, string $t): string => FieldPath::label($p, $schema, $item) . ': ' . $t, array_keys($errors), $errors));

            return null;
        }

        return $item;
    }

    /**
     * Checks the relations and bands with the rooms of the site and stages them; a loop is refused
     * with the rooms and relations that make it.
     *
     * @param array<string, mixed>            $rules
     * @param list<array<string, mixed>>|null $relations null: unchanged
     * @param list<array<string, mixed>>|null $bands     null: unchanged
     * @param array<string, string>           $back
     *
     * @return array<string, string>
     */
    private static function stage(array $rules, ?array $relations, ?array $bands, array $back): array
    {
        if ($relations !== null) {
            $rules['relations']['items'] = $relations;
        }
        if ($bands !== null) {
            $rules['price_bands']['items'] = $bands;
        }
        $services = EditorServices::instance()->services();
        $rooms = $services->roomTypes()->all();
        try {
            $config = RelationsConfig::fromArray(['relations' => $rules['relations'], 'price_bands' => $rules['price_bands']]);
            $errors = (new RelationValidator())->validate($config, $rooms, array_map(static fn ($p): string => $p->id, $services->properties()->all()));
        } catch (InvalidConfig $invalid) {
            $config = null;
            $errors = $invalid->errors();
        }
        if ($errors !== []) {
            $cycle = $config === null ? null : RelationCycle::find($config->relations, $rooms);
            $names = array_column(array_map(static fn ($r): array => ['code' => $r->code, 'name' => $r->name], $rooms), 'name', 'code');
            $message = $cycle !== null
                ? RelationText::loop($cycle['rooms'], array_map(static fn (Relation $r): string => $r->label !== '' ? $r->label : $r->id, $cycle['relations']), $names)
                : __('The relations were not kept: they name a room, a property or a relation that does not exist.', 'ritmo-revenue');
            Notice::flash(Notice::ERROR, $message, $cycle !== null ? [] : array_map(static fn (ValidationError $e): string => EditorText::problem($e), $errors));

            return $back;
        }
        EditorServices::instance()->drafts()->stage(get_current_user_id(), $rules, ['relations.items', 'price_bands.items']);
        Notice::flash(Notice::SUCCESS, __('Change kept in your draft (not saved yet).', 'ritmo-revenue'));

        return $back;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private static function indexOf(array $items, string $id): ?int
    {
        foreach ($items as $index => $item) {
            if ($id !== '' && ($item['id'] ?? null) === $id) {
                return $index;
            }
        }

        return null;
    }
}
