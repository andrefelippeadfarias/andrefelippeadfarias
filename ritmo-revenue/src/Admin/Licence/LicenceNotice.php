<?php

declare(strict_types=1);

namespace Ritmo\Admin\Licence;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Domain\Licence\LicenceReason;
use Ritmo\Domain\Licence\LicenceStanding;
use Throwable;

/**
 * The licence notice on the plugin's screens (admin_notices), to everyone who sees them: a
 * warning during the grace period with the date it ends, an error once it has ended (shadow mode,
 * assistant off), a note without a licence. Not on the licence tab itself, which says it all.
 */
final class LicenceNotice
{
    private function __construct()
    {
    }

    public static function render(): void
    {
        if (!Capability::can(Capability::VIEW) || !self::onPluginScreen()) {
            return;
        }
        try {
            $services = LicenceServices::instance();
            $verdict = $services->licence()->verdict();
            $now = $services->services()->clock()->now();
        } catch (Throwable) {
            return;
        }
        if ($verdict->standing === LicenceStanding::Active) {
            return;
        }
        $class = match ($verdict->standing) {
            LicenceStanding::Grace => 'notice-warning',
            LicenceStanding::Lapsed => 'notice-error',
            default => $verdict->reason === LicenceReason::TEST_PROVIDER ? 'notice-warning' : 'notice-info',
        };
        $link = Capability::can(Capability::MANAGE_CONNECTIONS)
            ? sprintf(' <a href="%s">%s</a>', esc_url(Menu::url(Menu::SETTINGS, ['tab' => LicenceScreen::TAB])), esc_html__('Open the licence settings', 'ritmo-revenue'))
            : '';
        printf(
            '<div class="notice %s" data-ritmo="licence-notice" data-standing="%s"><p><strong>%s</strong> %s %s%s</p></div>',
            esc_attr($class),
            esc_attr($verdict->standing->value),
            esc_html__('Ritmo Revenue licence:', 'ritmo-revenue'),
            esc_html(LicenceText::reason($verdict)),
            esc_html(LicenceText::consequence($verdict, $now, wp_timezone_string())),
            $link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_url and esc_html
        );
    }

    /**
     * A screen of the plugin other than Settings › Licence.
     */
    private static function onPluginScreen(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || !str_contains((string) $screen->id, Menu::TOP)) {
            return false;
        }
        $query = Input::query();

        return !($query->text('page', 64) === Menu::SETTINGS && $query->text('tab', 32) === LicenceScreen::TAB);
    }
}
