<?php

declare(strict_types=1);

namespace Ritmo\Admin\Licence;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Admin\Components\Tab;
use Ritmo\Admin\Notice;
use Ritmo\Admin\Screens\Page;
use Ritmo\Admin\View;
use Ritmo\Connectors\Licence\StubLicenceProvider;
use Ritmo\Domain\Licence\LicenceStanding;

/**
 * Settings › Licence: where the licence stands, its end date, the last check and the grace
 * period, the key field (write-only: the saved key is never printed back), "Check now", removing
 * the key, and the update channel. For those who manage the connections.
 */
final class LicenceScreen
{
    public const TAB = 'licence';

    private function __construct()
    {
    }

    public static function tab(): Tab
    {
        return new Tab(self::TAB, __('Licence', 'ritmo-revenue'), Capability::MANAGE_CONNECTIONS);
    }

    /**
     * Renders the tab inside the Settings screen (Page::open() has checked the capability).
     */
    public static function render(Page $page): void
    {
        Page::require(Capability::MANAGE_CONNECTIONS);
        View::render('admin/licence/frame', [
            'page' => $page,
            'header' => Page::headerOf($page->slug, $page->title, $page->properties, $page->property, $page->tabs, $page->tab),
            'notice' => Notice::take(),
            'view' => self::view(),
        ]);
    }

    /**
     * Everything the template prints, already in words.
     *
     * @return array<string, mixed>
     */
    public static function view(): array
    {
        $services = LicenceServices::instance();
        $licence = $services->licence();
        $settings = $licence->settings();
        $record = $licence->record();
        $verdict = $licence->verdict();
        $now = $services->services()->clock()->now();
        $zone = wp_timezone_string();
        $key = $licence->keyStatus();
        $updates = $services->updates();
        $release = $updates->offer();
        $channel = $updates->settings();

        return [
            'standing' => $verdict->standing->value,
            'standing_text' => LicenceText::standing($verdict->standing),
            'reason' => LicenceText::reason($verdict),
            'consequence' => LicenceText::consequence($verdict, $now, $zone),
            'tone' => match ($verdict->standing) {
                LicenceStanding::Active => 'good',
                LicenceStanding::Grace => 'attention',
                LicenceStanding::Lapsed => 'critical',
                LicenceStanding::Unlicensed => 'info',
            },
            'key_saved' => $key->configured,
            'key_saved_at' => $key->configured && $key->updatedAt !== null ? NightDate::instant($key->updatedAt, $zone) : '',
            'expires' => $verdict->expiresAt !== null ? NightDate::date($verdict->expiresAt, $zone) : ($record->status === '' ? __('Not known yet', 'ritmo-revenue') : __('No end date', 'ritmo-revenue')),
            'last_answer' => $record->lastAnswerAt !== null ? NightDate::instant($record->lastAnswerAt, $zone) : __('Never', 'ritmo-revenue'),
            'last_attempt' => $record->lastAttemptAt !== null ? NightDate::instant($record->lastAttemptAt, $zone) : __('Never', 'ritmo-revenue'),
            'last_outcome' => LicenceText::answer($record->lastAttemptStatus),
            'grace_ends' => $verdict->graceEndsAt !== null ? NightDate::date($verdict->graceEndsAt, $zone) : '',
            'grace_days' => $settings->grace->days,
            'message' => LicenceText::serverMessage($record->message),
            'writing' => $verdict->allowsWriting(),
            'provider' => match (true) {
                $record->providerId === StubLicenceProvider::ID => __('Test provider (development mode)', 'ritmo-revenue'),
                $settings->server !== null => __('Licence server', 'ritmo-revenue') . ' (' . $settings->server->host . ')',
                default => __('No licence server is configured on this site.', 'ritmo-revenue'),
            },
            'server_invalid' => $settings->serverInvalid,
            'dev_mode' => $settings->devMode,
            'post_url' => admin_url('admin-post.php'),
            'installed_version' => RITMO_VERSION,
            'updates_on' => $channel->enabled(),
            'update_version' => $release?->version ?? '',
            'update_problem' => $updates->lastProblem() !== null ? LicenceText::updateRefusal((string) $updates->lastProblem()) : '',
            'update_url' => admin_url('update-core.php'),
        ];
    }
}
