<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use DateTimeImmutable;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Labels;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Application\ShadowRun\ShadowRunService;
use Ritmo\WordPress\Scheduler;

/**
 * Settings › Diagnostics in words (admin spec 5.14): versions, the scheduler, run locks, the last
 * runs and one run's stages, the recent log with its context as "Run: 12 · Stage: report" (never
 * as data), and the support file for whoever manages the connections.
 */
final class DiagnosticsView
{
    public const LOG_ROWS = 100;

    private const LEVELS = ['debug', 'info', 'warning', 'error'];

    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(Page $page): array
    {
        $services = ScreenServices::instance()->services();
        $query = Input::query();
        $timezone = $page->property?->timezone ?? 'UTC';
        $migrator = $services->migrator();
        $cron = Scheduler::state();
        $runs = $services->runs()->latest(null, 20);
        $opened = $query->int('run', 0, 0);
        $level = $query->choice('level', self::LEVELS, 'info');

        return [
            'versions' => [
                /* translators: %s: version */
                sprintf(__('Plugin %s', 'ritmo-revenue'), RITMO_VERSION),
                /* translators: 1: version of the database, 2: latest version */
                sprintf(__('Database %1$s of %2$s', 'ritmo-revenue'), number_format_i18n($migrator->currentVersion()), number_format_i18n($migrator->latestVersion())),
                /* translators: %s: version */
                sprintf(__('WordPress %s', 'ritmo-revenue'), get_bloginfo('version')),
                /* translators: %s: version */
                sprintf(__('PHP %s', 'ritmo-revenue'), PHP_VERSION),
                function_exists('sodium_crypto_secretbox') ? __('Encryption available', 'ritmo-revenue') : __('Encryption missing: keys cannot be saved', 'ritmo-revenue'),
                match ($services->vault()->protection()) {
                    'unavailable' => __('RITMO_SECRET_KEY in wp-config.php is shorter than 32 characters: saved keys cannot be used until it is fixed.', 'ritmo-revenue'),
                    'database_only' => __('Keys are protected only by the database: add RITMO_SECRET_KEY to wp-config.php.', 'ritmo-revenue'),
                    default => __('Keys are protected by a key in wp-config.php.', 'ritmo-revenue'),
                },
            ],
            'scheduler' => [
                $cron['disabled'] ? __('WP-Cron is off: a server cron must call wp-cron.php every 5 minutes.', 'ritmo-revenue') : __('WP-Cron is on: runs start when the site is visited.', 'ritmo-revenue'),
                $cron['next_run'] === null ? __('No tick is scheduled.', 'ritmo-revenue')
                    /* translators: %s: date and time */
                    : sprintf(__('Next tick: %s', 'ritmo-revenue'), NightDate::instant(new DateTimeImmutable('@' . $cron['next_run']), $timezone)),
            ],
            'locks' => array_map(static function ($p) use ($services, $timezone): string {
                $holder = $services->locks()->holder(ShadowRunService::lockKey($p->id));

                return $holder === null
                    /* translators: %s: property */
                    ? sprintf(__('%s: free', 'ritmo-revenue'), $p->name)
                    /* translators: %s: property */
                    : sprintf(__('%s: a run is going on', 'ritmo-revenue'), $p->name);
            }, $page->properties),
            'runs' => array_map(static fn (array $r): array => self::run($r, $timezone, $page), $runs),
            'run' => $opened === 0 ? null : self::detail($services->runs()->find($opened), $timezone),
            'log' => array_map(static fn (array $l): array => [
                'level' => self::levelLabel((string) $l['level']),
                'when' => NightDate::instant($l['created_at'], $timezone),
                'message' => $services->sanitizer()->message($l['message']),
                'context' => self::context($services->sanitizer()->context($l['context'])),
            ], $services->logger()->recent(self::LOG_ROWS, $level)),
            'level' => $level,
            'levels' => array_combine(self::LEVELS, array_map(self::levelLabel(...), self::LEVELS)),
            'can_download' => Capability::can(Capability::MANAGE_CONNECTIONS),
            'filter_url' => admin_url('admin.php'),
        ];
    }

    /**
     * @param array<string, mixed> $run
     *
     * @return array<string, string>
     */
    private static function run(array $run, string $timezone, Page $page): array
    {
        $seconds = $run['finished_at'] === null ? null : $run['finished_at']->getTimestamp() - $run['started_at']->getTimestamp();

        return [
            'id' => (string) $run['id'],
            'property' => $run['property_id'],
            'kind' => Labels::runKind($run['kind']),
            'mode' => Labels::mode($run['mode']->value),
            'status' => Labels::runStatus($run['status']),
            'started' => NightDate::instant($run['started_at'], $timezone),
            /* translators: %s: seconds */
            'duration' => $seconds === null ? '—' : sprintf(_n('%s second', '%s seconds', $seconds, 'ritmo-revenue'), number_format_i18n($seconds)),
            'stage' => Labels::runStage($run['stage']),
            'error' => $run['error'] === null || $run['error'] === '' ? '' : __('It stopped with an error; see the log.', 'ritmo-revenue'),
            'url' => $page->url(['run' => $run['id']]) . '#ritmo-run-detail',
        ];
    }

    /**
     * @param array<string, mixed>|null $run
     *
     * @return array<string, mixed>|null
     */
    private static function detail(?array $run, string $timezone): ?array
    {
        if ($run === null) {
            return null;
        }
        $stages = [];
        foreach ($run['summary'] as $stage => $summary) {
            if (!is_string($stage) || !is_array($summary)) {
                continue;
            }
            $stages[] = ['name' => Labels::runStage($stage), 'facts' => self::context($summary)];
        }

        return [
            /* translators: 1: run number, 2: kind of run */
            'title' => sprintf(__('Run %1$s · %2$s', 'ritmo-revenue'), number_format_i18n($run['id']), Labels::runKind($run['kind'])),
            'status' => Labels::runStatus($run['status']),
            'started' => NightDate::instant($run['started_at'], $timezone),
            'stages' => $stages,
        ];
    }

    /**
     * Facts as "Label: value" joined by " · ": known keys translated, others in words; nested
     * lists are counted, never printed as data.
     *
     * @param array<string, mixed> $context
     */
    public static function context(array $context): string
    {
        $parts = [];
        foreach ($context as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $label = match ($key) {
                'run_id', 'run' => __('Run', 'ritmo-revenue'),
                'stage' => __('Stage', 'ritmo-revenue'),
                'duration_ms' => __('Duration (ms)', 'ritmo-revenue'),
                'property', 'property_id' => __('Property', 'ritmo-revenue'),
                'provider' => __('System', 'ritmo-revenue'),
                'status' => __('Status', 'ritmo-revenue'),
                default => ucfirst(str_replace('_', ' ', $key)),
            };
            $parts[] = $label . ': ' . match (true) {
                is_array($value) => sprintf(
                    /* translators: %s: number of items */
                    _n('%s item', '%s items', count($value), 'ritmo-revenue'),
                    number_format_i18n(count($value))
                ),
                is_bool($value) => $value ? __('yes', 'ritmo-revenue') : __('no', 'ritmo-revenue'),
                $value === null => '—',
                default => str_replace(['{', '}'], '', (string) $value),
            };
        }

        return implode(' · ', $parts);
    }

    /**
     * The word of a log level.
     */
    public static function levelLabel(string $level): string
    {
        return match ($level) {
            'debug' => __('detail', 'ritmo-revenue'),
            'info' => __('information', 'ritmo-revenue'),
            'warning' => __('warning', 'ritmo-revenue'),
            'error' => __('error', 'ritmo-revenue'),
            default => __('other', 'ritmo-revenue'),
        };
    }
}
