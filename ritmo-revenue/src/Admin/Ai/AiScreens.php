<?php

declare(strict_types=1);

namespace Ritmo\Admin\Ai;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Screen;

/**
 * The registry of the Assistant screen (for the integration): the screen for the menu (slug
 * AiScreen::SLUG, after Operations), its form actions for the dispatcher, its assets
 * (assets/ai.css after editors.css, assets/ai.js), its REST routes (Rest\Ai\AiRoutes) and the
 * "explain" button the Alerts screen and the run reports may show (explainView()).
 */
final class AiScreens
{
    public const ASSETS = ['ai'];

    private function __construct()
    {
    }

    /**
     * @return list<Screen>
     */
    public static function screens(): array
    {
        return [new Screen(AiScreen::SLUG, __('Assistant', 'ritmo-revenue'), Capability::VIEW, AiScreen::tabs(), AiScreen::render(...))];
    }

    /**
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        return AiActions::actions();
    }

    /**
     * The view of templates/admin/ai/explain for an alert or a run report, or null when the person
     * may not use the assistant or it is not ready (no key, off, over the cap).
     *
     * @param string $kind "alert" or "run"
     *
     * @return array{rest_url: string, rest_nonce: string, label: string}|null
     */
    public static function explainView(string $kind, int $id): ?array
    {
        if ($id <= 0 || !AiCapability::can() || !AiServices::instance()->assistant()->status()->isReady()) {
            return null;
        }

        return [
            'rest_url' => rest_url($kind === 'alert' ? 'ritmo/v1/ai/alerts/' . $id . '/explain' : 'ritmo/v1/ai/runs/' . $id . '/report'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'label' => $kind === 'alert' ? __('Explain in plain words', 'ritmo-revenue') : __('Put this report in words', 'ritmo-revenue'),
        ];
    }
}
