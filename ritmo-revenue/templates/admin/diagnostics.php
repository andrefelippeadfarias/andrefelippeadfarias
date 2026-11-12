<?php
/**
 * Diagnostics: versions, runs, locks, WP-Cron, the emergency switch and the uninstall choice.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_time = static fn ($instant): string => $instant instanceof DateTimeInterface ? wp_date('Y-m-d H:i:s', $instant->getTimestamp()) : '–';
$ritmo_yes = static fn (bool $value): string => $value ? __('Yes', 'ritmo-revenue') : __('No', 'ritmo-revenue');

View::render('admin/partials/header', $view);
?>
<div class="ritmo-grid">
    <section class="ritmo-card" data-ritmo="versions">
        <h2><?php esc_html_e('Versions', 'ritmo-revenue'); ?></h2>
        <table class="widefat striped ritmo-table">
            <tbody>
                <tr><th scope="row"><?php esc_html_e('Plugin', 'ritmo-revenue'); ?></th><td><?php echo esc_html($view['versions']['plugin']); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e('Database', 'ritmo-revenue'); ?></th>
                    <td data-ritmo="db-version"><?php echo esc_html(sprintf(/* translators: 1: installed version, 2: latest version */ __('%1$d of %2$d', 'ritmo-revenue'), $view['versions']['db_current'], $view['versions']['db_latest'])); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e('WordPress', 'ritmo-revenue'); ?></th><td><?php echo esc_html($view['versions']['wordpress']); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e('PHP', 'ritmo-revenue'); ?></th><td><?php echo esc_html($view['versions']['php']); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e('Sodium encryption', 'ritmo-revenue'); ?></th><td><?php echo esc_html($ritmo_yes($view['versions']['sodium'])); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e('Pricing calendar read (POST)', 'ritmo-revenue'); ?></th><td><?php echo esc_html($ritmo_yes($view['calendar_read'])); ?></td></tr>
            </tbody>
        </table>
    </section>

    <section class="ritmo-card" data-ritmo="cron">
        <h2><?php esc_html_e('Scheduler and locks', 'ritmo-revenue'); ?></h2>
        <p><?php echo esc_html($view['cron']['disabled'] ? __('WP-Cron is disabled on this site (DISABLE_WP_CRON); a server cron must call wp-cron.php.', 'ritmo-revenue') : __('WP-Cron is enabled.', 'ritmo-revenue')); ?></p>
        <p><?php echo esc_html(sprintf(/* translators: %s: date and time */ __('Next resume of interrupted runs: %s', 'ritmo-revenue'), $view['cron']['next_run'] === null ? __('not scheduled', 'ritmo-revenue') : wp_date('Y-m-d H:i', $view['cron']['next_run']))); ?></p>
        <table class="widefat striped ritmo-table" data-ritmo="locks">
            <thead><tr><th scope="col"><?php esc_html_e('Property', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Run lock', 'ritmo-revenue'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($view['locks'] as $ritmo_lock) : ?>
                    <tr>
                        <td><?php echo esc_html($ritmo_lock['property']); ?></td>
                        <td><?php echo esc_html($ritmo_lock['holder'] === null
                            ? __('Free', 'ritmo-revenue')
                            : sprintf(/* translators: %s: date and time */ __('Held until %s', 'ritmo-revenue'), $ritmo_time($ritmo_lock['holder']['expires_at'] ?? null))); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>

<section class="ritmo-card" data-ritmo="runs">
    <h2><?php esc_html_e('Last runs', 'ritmo-revenue'); ?></h2>
    <?php if ($view['runs'] === []) : ?>
        <p><?php esc_html_e('No run yet.', 'ritmo-revenue'); ?></p>
    <?php else : ?>
        <table class="widefat striped ritmo-table">
            <thead><tr>
                <th scope="col">#</th>
                <th scope="col"><?php esc_html_e('Property', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Status', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Last stage', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Started', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Finished', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Error', 'ritmo-revenue'); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($view['runs'] as $ritmo_run) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $ritmo_run['id']); ?></td>
                        <td><?php echo esc_html($ritmo_run['property_id']); ?></td>
                        <td><span class="ritmo-status ritmo-status-<?php echo esc_attr($ritmo_run['status']); ?>"><?php echo esc_html($ritmo_run['status']); ?></span></td>
                        <td><?php echo esc_html($ritmo_run['stage']); ?></td>
                        <td><?php echo esc_html($ritmo_time($ritmo_run['started_at'])); ?></td>
                        <td><?php echo esc_html($ritmo_time($ritmo_run['finished_at'])); ?></td>
                        <td><?php echo esc_html((string) $ritmo_run['error']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<div class="ritmo-grid">
    <section class="ritmo-card" data-ritmo="switch">
        <h2><?php esc_html_e('Emergency switch', 'ritmo-revenue'); ?></h2>
        <p class="<?php echo esc_attr($view['switch']['engaged'] ? 'ritmo-error' : ''); ?>">
            <?php
            if (!$view['switch']['engaged']) {
                esc_html_e('Released: shadow runs may start.', 'ritmo-revenue');
            } elseif ($view['switch']['by_constant']) {
                esc_html_e('Engaged by the constant RITMO_KILL_SWITCH in wp-config.php: remove it there to release.', 'ritmo-revenue');
            } else {
                esc_html_e('Engaged: no run starts and running ones stop before their next stage.', 'ritmo-revenue');
            }
            ?>
        </p>
        <?php if ($view['can_manage']) : ?>
            <form method="post" action="<?php echo esc_url($view['post_url']); ?>">
                <?php wp_nonce_field('ritmo_emergency_switch'); ?>
                <input type="hidden" name="action" value="ritmo_emergency_switch">
                <input type="hidden" name="switch" value="<?php echo esc_attr($view['switch']['engaged'] ? 'release' : 'engage'); ?>">
                <?php submit_button($view['switch']['engaged'] ? __('Release the switch', 'ritmo-revenue') : __('Engage the switch', 'ritmo-revenue'), $view['switch']['engaged'] ? 'secondary' : 'delete', 'switch_submit', false); ?>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($view['can_manage']) : ?>
        <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card">
            <?php wp_nonce_field('ritmo_save_settings'); ?>
            <input type="hidden" name="action" value="ritmo_save_settings">
            <h2><?php esc_html_e('Uninstall', 'ritmo-revenue'); ?></h2>
            <label><input type="checkbox" name="delete_data" value="1" <?php checked($view['delete_data']); ?>> <?php esc_html_e('Delete all plugin data (tables, settings, keys, role) when the plugin is deleted', 'ritmo-revenue'); ?></label>
            <?php submit_button(__('Save', 'ritmo-revenue'), 'secondary', 'save_settings', false); ?>
        </form>
    <?php endif; ?>
</div>

<section class="ritmo-card" data-ritmo="log">
    <h2><?php esc_html_e('Recent log', 'ritmo-revenue'); ?></h2>
    <?php if ($view['log'] === []) : ?>
        <p><?php esc_html_e('Nothing logged.', 'ritmo-revenue'); ?></p>
    <?php else : ?>
        <table class="widefat striped ritmo-table">
            <tbody>
                <?php foreach ($view['log'] as $ritmo_entry) : ?>
                    <tr>
                        <td><?php echo esc_html($ritmo_time($ritmo_entry['created_at'] ?? null)); ?></td>
                        <td><?php echo esc_html((string) ($ritmo_entry['level'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($ritmo_entry['message'] ?? '')); ?></td>
                        <td><code><?php echo esc_html((string) wp_json_encode($ritmo_entry['context'] ?? [])); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php View::render('admin/partials/footer', $view); ?>
