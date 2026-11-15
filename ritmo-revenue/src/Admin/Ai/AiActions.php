<?php

declare(strict_types=1);

namespace Ritmo\Admin\Ai;

use InvalidArgumentException;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Editors\EditorServices;
use Ritmo\Admin\Editors\OrderFormDraft;
use Ritmo\Admin\Notice;
use Ritmo\Application\Ai\AiDraftQueue;
use Ritmo\Domain\Ai\AiDraft;
use Ritmo\Domain\Ai\AiEffort;
use Ritmo\Domain\Ai\AiModel;
use Ritmo\Domain\Ai\AiPurpose;
use Ritmo\Domain\Ai\AiStopped;
use Ritmo\Domain\Ai\PriceTable;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Infrastructure\Secrets\SecretUnavailable;

/**
 * The forms of the Assistant screen. The dispatcher checks POST, the capability and the nonce
 * first; the handlers that ask the assistant check ritmo_use_ai again before anything (the
 * dispatcher's capability is the screen's). Nothing here writes a price, an order or a rule
 * version: a draft is opened in its editor, where a person decides.
 */
final class AiActions
{
    public const SETTINGS = 'ritmo_ai_settings_save';
    public const FORGET_KEY = 'ritmo_ai_key_forget';
    public const TEST = 'ritmo_ai_test';
    public const DRAFT = 'ritmo_ai_draft';
    public const OPEN = 'ritmo_ai_draft_open';
    public const DISCARD = 'ritmo_ai_draft_discard';
    public const WEEKLY = 'ritmo_ai_weekly';

    private function __construct()
    {
    }

    /**
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        $page = AiScreen::SLUG;

        return [
            new FormAction(self::SETTINGS, Capability::MANAGE_CONNECTIONS, $page, self::saveSettings(...)),
            new FormAction(self::FORGET_KEY, Capability::MANAGE_CONNECTIONS, $page, self::forgetKey(...)),
            new FormAction(self::TEST, Capability::MANAGE_CONNECTIONS, $page, self::test(...)),
            new FormAction(self::DRAFT, Capability::VIEW, $page, self::draft(...)),
            new FormAction(self::WEEKLY, Capability::VIEW, $page, self::weekly(...)),
            new FormAction(self::OPEN, Capability::MANAGE_RULES, $page, self::open(...), true, 'draft'),
            new FormAction(self::DISCARD, Capability::VIEW, $page, self::discard(...), true, 'draft'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function saveSettings(Input $form): array
    {
        $services = AiServices::instance();
        $store = $services->settings();
        $key = $form->secret('api_key');
        if ($key !== '') {
            try {
                $store->putKey($key);
            } catch (InvalidArgumentException) {
                Notice::flash(Notice::ERROR, __('A Claude API key cannot contain spaces or control characters. The key was not saved.', 'ritmo-revenue'));

                return ['tab' => 'settings'];
            } catch (SecretUnavailable) {
                Notice::flash(Notice::ERROR, __('The key could not be saved: the secrets vault is not available. See Settings, Diagnostics.', 'ritmo-revenue'));

                return ['tab' => 'settings'];
            }
        }
        $current = $store->settings();
        $prices = [];
        foreach (array_keys($current->priceTable()->toArray()) as $model) {
            $row = [];
            foreach (['input', 'output', 'cache_read', 'cache_write'] as $kind) {
                $text = str_replace(',', '.', $form->text('price_' . str_replace(['-', '.'], '_', $model) . '_' . $kind, 20));
                $row[$kind] = is_numeric($text) ? (float) $text : $current->priceTable()->forModel($model === PriceTable::OTHER ? 'any-other' : $model)->toArray()[$kind];
            }
            $prices[$model] = $row;
        }
        $cap = str_replace(',', '.', $form->text('monthly_cap_usd', 20));
        $changes = [
            'enabled' => $form->bool('enabled'),
            'model' => $form->choice('model', AiModel::CHOICES, $current->model),
            'effort' => $form->choice('effort', array_map(static fn (AiEffort $e): string => $e->value, AiEffort::cases()), $current->effort->value),
            'fallback' => $form->bool('fallback'),
            'monthly_cap_usd' => is_numeric($cap) ? (float) $cap : -1.0,
            'timeout_seconds' => $form->int('timeout_seconds', $current->timeoutSeconds),
            'prices' => $prices,
        ];
        try {
            $settings = $current->with($changes);
        } catch (InvalidConfig $e) {
            Notice::flash(Notice::ERROR, __('The settings were not saved:', 'ritmo-revenue'), array_map(static fn (ValidationError $error): string => self::fieldError($error), $e->errors()));

            return ['tab' => 'settings'];
        }
        if (!$settings->modelAvailable()) {
            Notice::flash(Notice::ERROR, __('The saved key cannot use this model. Choose one of the models the connection test found.', 'ritmo-revenue'));

            return ['tab' => 'settings'];
        }
        $store->save($settings);
        Notice::flash(Notice::SUCCESS, $settings->enabled
            ? __('Assistant settings saved. The assistant is on.', 'ritmo-revenue')
            : __('Assistant settings saved. The assistant is off: nothing is sent to Claude.', 'ritmo-revenue'));

        return ['tab' => 'settings'];
    }

    /**
     * @return array<string, string>
     */
    public static function forgetKey(Input $form): array
    {
        AiServices::instance()->settings()->forgetKey();
        Notice::flash(Notice::SUCCESS, __('The Claude API key was removed. Nothing is sent to Claude until a new key is saved.', 'ritmo-revenue'));

        return ['tab' => 'settings'];
    }

    /**
     * @return array<string, string>
     */
    public static function test(Input $form): array
    {
        try {
            $models = AiServices::instance()->assistant()->testConnection();
            /* translators: %s: names of the models, separated by commas */
            Notice::flash(Notice::SUCCESS, sprintf(__('Connected to Claude. The key may use: %s.', 'ritmo-revenue'), implode(', ', array_map(static fn ($m): string => AiWords::model($m->id), $models))));
        } catch (AiStopped $e) {
            self::stopped($e);
        }

        return ['tab' => 'settings'];
    }

    /**
     * @return array<string, string>
     */
    public static function draft(Input $form): array
    {
        self::requireUse();
        $property = AiData::property($form->text('property_id', 64));
        $text = $form->multiline('request', 1000);
        if ($property === null || $text === '') {
            Notice::flash(Notice::ERROR, __('Write the request and choose a property.', 'ritmo-revenue'));

            return ['tab' => 'ask'];
        }
        $input = AiData::request($property, $text);
        try {
            $draft = AiServices::instance()->assistant()->draft($text, $input['data'], $input['context'], AiData::locale());
        } catch (AiStopped $e) {
            self::stopped($e);

            return ['tab' => 'ask', 'property' => $property->id];
        } catch (InvalidArgumentException) {
            Notice::flash(Notice::ERROR, __('A request has from 1 to 1000 characters.', 'ritmo-revenue'));

            return ['tab' => 'ask', 'property' => $property->id];
        }
        AiServices::instance()->drafts()->add($property->id, get_current_user_id(), $text, $draft);
        /* translators: %s: what the draft would do */
        Notice::flash(Notice::SUCCESS, sprintf(__('Draft ready for your review: %s Nothing changes until you open it in its editor and save it there.', 'ritmo-revenue'), $draft->summary));

        return ['tab' => 'ask', 'property' => $property->id];
    }

    /**
     * @return array<string, string>
     */
    public static function weekly(Input $form): array
    {
        self::requireUse();
        $property = AiData::property($form->text('property_id', 64));
        if ($property === null) {
            return ['tab' => 'ask'];
        }
        $input = AiData::weekly($property);
        try {
            $text = AiServices::instance()->assistant()->reportText(AiPurpose::WeeklyReport, $input['about'], $input['facts'], AiData::locale());
            AiResult::keep(__('This week in words', 'ritmo-revenue'), $text->text, $text->model, $text->fallback);
        } catch (AiStopped $e) {
            self::stopped($e);
        }

        return ['tab' => 'ask', 'property' => $property->id];
    }

    /**
     * Opens a draft in its editor: an order in the order form (filled in, not given), rule changes
     * in the person's rules draft (shown in the review, not saved).
     *
     * @return array<string, string>
     */
    public static function open(Input $form): array
    {
        self::requireUse();
        $services = AiServices::instance();
        $id = $form->text('draft', 64);
        $row = $services->drafts()->find($id);
        if ($row === null || $row['status'] !== AiDraftQueue::PENDING) {
            Notice::flash(Notice::ERROR, __('That draft is not waiting any more.', 'ritmo-revenue'));

            return ['tab' => 'ask'];
        }
        $draft = $row['draft'];
        if ($draft->kind === AiDraft::ORDER) {
            $fields = $services->handOff()->orderFields($draft) ?? [];
            OrderFormDraft::keep($fields);
            $services->drafts()->close($id, AiDraftQueue::OPENED);
            Notice::flash(Notice::SUCCESS, __('The order form is filled in with the assistant\'s draft. Check every field; the order is only given when you save it.', 'ritmo-revenue'));

            return ['page' => Menu::ORDERS, 'tab' => 'new', 'type' => (string) $fields['type'], 'property' => $row['property']];
        }
        $editors = EditorServices::instance();
        $userId = get_current_user_id();
        try {
            $staged = $services->handOff()->ruleDraft($draft, $editors->drafts()->rules($userId));
        } catch (InvalidArgumentException) {
            Notice::flash(Notice::ERROR, __('This draft touches a setting the assistant may not change. It was not opened.', 'ritmo-revenue'));

            return ['tab' => 'ask'];
        }
        if ($staged !== null) {
            $editors->drafts()->stage($userId, $staged['rules'], $staged['touched']);
        }
        $services->drafts()->close($id, AiDraftQueue::OPENED);
        Notice::flash(Notice::SUCCESS, __('The changes are in your rules draft. Review them; the rules only change when you save a new version.', 'ritmo-revenue'));

        return ['page' => Menu::RULES, 'tab' => 'review', 'property' => $row['property']];
    }

    /**
     * @return array<string, string>
     */
    public static function discard(Input $form): array
    {
        self::requireUse();
        try {
            AiServices::instance()->drafts()->close($form->text('draft', 64), AiDraftQueue::DISCARDED);
            Notice::flash(Notice::SUCCESS, __('Draft discarded.', 'ritmo-revenue'));
        } catch (InvalidArgumentException) {
            Notice::flash(Notice::ERROR, __('That draft is not waiting any more.', 'ritmo-revenue'));
        }

        return ['tab' => 'ask'];
    }

    private static function stopped(AiStopped $stopped): void
    {
        $words = AiWords::stopped($stopped);
        Notice::flash(Notice::ERROR, $words['message'], $words['details']);
    }

    private static function requireUse(): void
    {
        if (!AiCapability::can()) {
            wp_die(esc_html__('You are not allowed to do this.', 'ritmo-revenue'), '', ['response' => 403]);
        }
    }

    private static function fieldError(ValidationError $error): string
    {
        return match ($error->path) {
            'model' => __('Model: choose one of the models offered.', 'ritmo-revenue'),
            'effort' => __('Effort: choose low, medium or high.', 'ritmo-revenue'),
            /* translators: 1: lowest cap, 2: highest cap, in dollars */
            'monthly_cap_usd' => sprintf(__('Monthly cap: from %1$s to %2$s dollars.', 'ritmo-revenue'), number_format_i18n(1), number_format_i18n(10000)),
            /* translators: 1: shortest wait, 2: longest wait, in seconds */
            'timeout_seconds' => sprintf(__('Wait for an answer: from %1$d to %2$d seconds.', 'ritmo-revenue'), 15, 300),
            default => __('Prices: each price per million tokens is a number from 0 to 1000.', 'ritmo-revenue'),
        };
    }
}
