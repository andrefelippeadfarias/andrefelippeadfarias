<?php
/**
 * Settings › Diagnostics: versions, scheduler, locks, the last runs (one opened with its stages),
 * the log in words, and the support file.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<div class="ritmo-cards">
    <section class="ritmo-card">
        <h2><?php esc_html_e('Versions', 'ritmo-revenue'); ?></h2>
        <ul class="ritmo-list" data-ritmo="db-version"><?php foreach ($view['versions'] as $ritmo_line) : ?><li><?php echo esc_html($ritmo_line); ?></li><?php endforeach; ?></ul>
    </section>
    <section class="ritmo-card">
        <h2><?php esc_html_e('Scheduler', 'ritmo-revenue'); ?></h2>
        <ul class="ritmo-list"><?php foreach ($view['scheduler'] as $ritmo_line) : ?><li><?php echo esc_html($ritmo_line); ?></li><?php endforeach; ?></ul>
        <h3><?php esc_html_e('Run locks', 'ritmo-revenue'); ?></h3>
        <ul class="ritmo-list"><?php foreach ($view['locks'] as $ritmo_line) : ?><li><?php echo esc_html($ritmo_line); ?></li><?php endforeach; ?></ul>
    </section>
    <?php if ($view['can_download']) : ?>
        <section class="ritmo-card">
            <h2><?php esc_html_e('Support file', 'ritmo-revenue'); ?></h2>
            <p><?php esc_html_e('A file for support with versions, settings, rules, the last runs and the log. It never has keys, bookings, names or e-mail addresses.', 'ritmo-revenue'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ritmo_download_support_bundle'); ?>
                <input type="hidden" name="action" value="ritmo_download_support_bundle">
                <button type="submit" class="button" data-ritmo="support-bundle"><?php esc_html_e('Download the support file', 'ritmo-revenue'); ?></button>
            </form>
        </section>
    <?php endif; ?>
</div>

<?php if ($view['run'] !== null) : ?>
    <section id="ritmo-run-detail" class="ritmo-card ritmo-wide" data-ritmo="run-detail">
        <h2 tabindex="-1" data-ritmo-focus><?php echo esc_html($view['run']['title']); ?></h2>
        <p><?php echo esc_html($view['run']['status']); ?> · <?php echo esc_html($view['run']['started']); ?></p>
        <ul class="ritmo-list">
            <?php foreach ($view['run']['stages'] as $ritmo_stage) : ?>
                <li><strong><?php echo esc_html($ritmo_stage['name']); ?></strong><?php if ($ritmo_stage['facts'] !== '') : ?>: <?php echo esc_html($ritmo_stage['facts']); ?><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="ritmo-card ritmo-wide">
    <h2><?php esc_html_e('Last runs', 'ritmo-revenue'); ?></h2>
    <?php if ($view['runs'] === []) : ?>
        <p data-ritmo="empty"><?php esc_html_e('No run yet.', 'ritmo-revenue'); ?></p>
    <?php else : ?>
        <table class="widefat striped ritmo-table" data-ritmo="runs">
            <caption class="screen-reader-text"><?php esc_html_e('The last runs, newest first', 'ritmo-revenue'); ?></caption>
            <thead><tr>
                <th scope="col"><?php esc_html_e('Run', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Property', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Kind', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Mode', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Status', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Started', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Took', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Last step', 'ritmo-revenue'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($view['runs'] as $ritmo_run) : ?>
                <tr>
                    <th scope="row" data-label="<?php esc_attr_e('Run', 'ritmo-revenue'); ?>"><a href="<?php echo esc_url($ritmo_run['url']); ?>"><?php echo esc_html($ritmo_run['id']); ?></a></th>
                    <td data-label="<?php esc_attr_e('Property', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_run['property']); ?></td>
                    <td data-label="<?php esc_attr_e('Kind', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_run['kind']); ?></td>
                    <td data-label="<?php esc_attr_e('Mode', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_run['mode']); ?></td>
                    <td data-label="<?php esc_attr_e('Status', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_run['status']); ?> <?php echo esc_html($ritmo_run['error']); ?></td>
                    <td data-label="<?php esc_attr_e('Started', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_run['started']); ?></td>
                    <td data-label="<?php esc_attr_e('Took', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_run['duration']); ?></td>
                    <td data-label="<?php esc_attr_e('Last step', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_run['stage']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="ritmo-card ritmo-wide" data-ritmo="log">
    <h2><?php esc_html_e('Recent log', 'ritmo-revenue'); ?></h2>
    <form method="get" action="<?php echo esc_url($view['filter_url']); ?>" class="ritmo-inline">
        <input type="hidden" name="page" value="ritmo-revenue-settings">
        <input type="hidden" name="tab" value="diagnostics">
        <label for="ritmo-log-level"><?php esc_html_e('From level', 'ritmo-revenue'); ?></label>
        <select id="ritmo-log-level" name="level">
            <?php foreach ($view['levels'] as $ritmo_level => $ritmo_level_label) : ?><option value="<?php echo esc_attr($ritmo_level); ?>" <?php selected($ritmo_level, $view['level']); ?>><?php echo esc_html($ritmo_level_label); ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="button"><?php esc_html_e('Apply', 'ritmo-revenue'); ?></button>
    </form>
    <?php if ($view['log'] === []) : ?>
        <p data-ritmo="empty"><?php esc_html_e('Nothing logged at this level.', 'ritmo-revenue'); ?></p>
    <?php else : ?>
        <ul class="ritmo-log">
            <?php foreach ($view['log'] as $ritmo_line) : ?>
                <li><span class="ritmo-muted"><?php echo esc_html($ritmo_line['when']); ?> · <?php echo esc_html($ritmo_line['level']); ?></span> <?php echo esc_html($ritmo_line['message']); ?><?php if ($ritmo_line['context'] !== '') : ?> <span class="ritmo-muted"><?php echo esc_html($ritmo_line['context']); ?></span><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
