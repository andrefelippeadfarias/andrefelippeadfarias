<?php
/**
 * Overview: tonight and the next nights, the occupancy windows, pace, what needs a person, the
 * last run and the next runs. Every text arrives formatted; this file only prints.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

if ($view['empty']) : ?>
    <div class="ritmo-empty" data-ritmo="empty">
        <h2><?php esc_html_e('Start the setup', 'ritmo-revenue'); ?></h2>
        <p><?php esc_html_e('There is no property yet. The setup connects your systems and shows what the plugin would do, in shadow mode: nothing is written.', 'ritmo-revenue'); ?></p>
        <p><a class="button button-primary" href="<?php echo esc_url($view['setup_url']); ?>"><?php esc_html_e('Start the setup', 'ritmo-revenue'); ?></a></p>
    </div>
    <?php
    return;
endif;
?>
<?php if ($view['site'] !== null) : ?>
    <section class="ritmo-card ritmo-wide" data-ritmo="properties-table">
        <h2><?php esc_html_e('All properties', 'ritmo-revenue'); ?></h2>
        <?php if (!$view['site']['ok']) : View::render('admin/screens/parts/card-error', $view['site']); else : ?>
            <table class="widefat striped ritmo-table">
                <caption class="screen-reader-text"><?php esc_html_e('Every property at a glance', 'ritmo-revenue'); ?></caption>
                <thead><tr>
                    <th scope="col"><?php esc_html_e('Property', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Mode', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Tonight', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Next 7 days', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Open alerts', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Nights waiting', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Last run', 'ritmo-revenue'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($view['site']['rows'] as $ritmo_row) : ?>
                    <tr>
                        <th scope="row" data-label="<?php esc_attr_e('Property', 'ritmo-revenue'); ?>"><a href="<?php echo esc_url($ritmo_row['url']); ?>"><?php echo esc_html($ritmo_row['name']); ?></a></th>
                        <td data-label="<?php esc_attr_e('Mode', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['mode']); ?></td>
                        <td data-label="<?php esc_attr_e('Tonight', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['tonight']); ?></td>
                        <td data-label="<?php esc_attr_e('Next 7 days', 'ritmo-revenue'); ?>"><span class="ritmo-badge ritmo-tone-<?php echo esc_attr($ritmo_row['signal']['tone']); ?>"><?php echo esc_html($ritmo_row['signal']['text']); ?></span> <?php echo esc_html($ritmo_row['occupancy']); ?></td>
                        <td data-label="<?php esc_attr_e('Open alerts', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['alerts']); ?></td>
                        <td data-label="<?php esc_attr_e('Nights waiting', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['waiting']); ?></td>
                        <td data-label="<?php esc_attr_e('Last run', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['last_run']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="ritmo-cards">
    <section class="ritmo-card ritmo-wide" data-ritmo="card-today">
        <h2><?php esc_html_e('Tonight and the next 3 nights', 'ritmo-revenue'); ?></h2>
        <?php if (!$view['soon']['ok']) : View::render('admin/screens/parts/card-error', $view['soon']); elseif ($view['soon']['rows'] === []) : ?>
            <p data-ritmo="empty"><?php esc_html_e('No room yet: add the rooms in the setup.', 'ritmo-revenue'); ?></p>
        <?php else : ?>
            <div class="ritmo-scroll" role="region" tabindex="0" aria-label="<?php esc_attr_e('Tonight and the next nights', 'ritmo-revenue'); ?>">
                <table class="widefat ritmo-table ritmo-soon">
                    <caption><?php echo esc_html($view['soon']['caption']); ?></caption>
                    <thead><tr>
                        <th scope="col"><?php esc_html_e('Room', 'ritmo-revenue'); ?></th>
                        <?php foreach ($view['soon']['columns'] as $ritmo_column) : ?>
                            <th scope="col"><?php echo esc_html($ritmo_column); ?></th>
                        <?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($view['soon']['rows'] as $ritmo_row) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($ritmo_row['room']); ?></th>
                            <?php foreach ($ritmo_row['cells'] as $ritmo_cell) : ?>
                                <td><?php echo esc_html($ritmo_cell['state']); ?><br><span class="ritmo-price" data-ritmo="price"><?php echo esc_html($ritmo_cell['price']); ?></span></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if (!$view['windows']['ok']) : ?>
        <section class="ritmo-card"><h2><?php esc_html_e('Occupancy', 'ritmo-revenue'); ?></h2><?php View::render('admin/screens/parts/card-error', $view['windows']); ?></section>
    <?php else : foreach ($view['windows']['cards'] as $ritmo_window) : ?>
        <section class="ritmo-card" data-ritmo="<?php echo esc_attr($ritmo_window['hook']); ?>">
            <h2><?php echo esc_html($ritmo_window['title']); ?></h2>
            <p class="ritmo-big"><?php echo esc_html($ritmo_window['occupancy']); ?></p>
            <p><span class="ritmo-badge ritmo-tone-<?php echo esc_attr($ritmo_window['signal']['tone']); ?>"><?php echo esc_html($ritmo_window['signal']['text']); ?></span> <?php echo esc_html($ritmo_window['goal']); ?></p>
            <p><?php echo esc_html($ritmo_window['revenue']); ?></p>
            <p class="ritmo-muted"><?php echo esc_html($ritmo_window['adr']); ?></p>
            <ul class="ritmo-list">
                <?php foreach ($ritmo_window['rooms'] as $ritmo_room) : ?>
                    <li><?php echo esc_html($ritmo_room['name']); ?>: <?php echo esc_html($ritmo_room['text']); ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; endif; ?>

    <section class="ritmo-card" data-ritmo="card-pace">
        <h2><?php esc_html_e('Pace, next 60 days', 'ritmo-revenue'); ?></h2>
        <?php if (!$view['pace']['ok']) : View::render('admin/screens/parts/card-error', $view['pace']); elseif ($view['pace']['empty']) : ?>
            <p data-ritmo="empty"><?php esc_html_e('No run yet: the first run measures the pace of every night.', 'ritmo-revenue'); ?></p>
        <?php else : ?>
            <ul class="ritmo-pace">
                <?php foreach ($view['pace']['items'] as $ritmo_item) : ?>
                    <li><a class="ritmo-badge ritmo-tone-<?php echo esc_attr($ritmo_item['tone']); ?>" href="<?php echo esc_url($ritmo_item['url']); ?>"><span aria-hidden="true"><?php echo esc_html($ritmo_item['symbol']); ?></span> <?php echo esc_html($ritmo_item['text']); ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="ritmo-card" data-ritmo="card-needs-you">
        <h2><?php esc_html_e('Needs you', 'ritmo-revenue'); ?></h2>
        <?php if (!$view['needs']['ok']) : View::render('admin/screens/parts/card-error', $view['needs']); else : ?>
            <?php $ritmo_needs = $view['needs']; ?>
            <?php if ($ritmo_needs['waiting'] === null && $ritmo_needs['alerts'] === [] && $ritmo_needs['todos'] === []) : ?>
                <p data-ritmo="empty"><?php esc_html_e('Nothing needs you now.', 'ritmo-revenue'); ?></p>
            <?php endif; ?>
            <?php foreach ($ritmo_needs['alerts'] as $ritmo_alert) : ?>
                <p><span class="ritmo-badge ritmo-tone-critical"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php esc_html_e('Critical', 'ritmo-revenue'); ?></span> <a href="<?php echo esc_url($ritmo_alert['url']); ?>"><?php echo esc_html($ritmo_alert['title']); ?></a> <span class="ritmo-muted"><?php echo esc_html($ritmo_alert['when']); ?></span></p>
            <?php endforeach; ?>
            <?php if ($ritmo_needs['waiting'] !== null) : ?>
                <p><?php echo esc_html($ritmo_needs['waiting']); ?>
                    <?php if ($ritmo_needs['can_approve']) : ?><a class="button" href="<?php echo esc_url($ritmo_needs['approvals_url']); ?>"><?php esc_html_e('Review', 'ritmo-revenue'); ?></a><?php endif; ?></p>
            <?php endif; ?>
            <?php if ($ritmo_needs['todos'] !== []) : ?>
                <ul class="ritmo-list">
                    <?php foreach ($ritmo_needs['todos'] as $ritmo_todo) : ?>
                        <li><a href="<?php echo esc_url($ritmo_needs['todo_url']); ?>"><?php echo esc_html($ritmo_todo['title']); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="ritmo-card" data-ritmo="card-last-run">
        <h2><?php esc_html_e('Last run', 'ritmo-revenue'); ?></h2>
        <?php if (!$view['last']['ok']) : View::render('admin/screens/parts/card-error', $view['last']); elseif ($view['last']['run'] === null) : ?>
            <p data-ritmo="empty"><?php esc_html_e('No run yet.', 'ritmo-revenue'); ?></p>
        <?php else : $ritmo_run = $view['last']['run']; ?>
            <p><strong><?php echo esc_html($ritmo_run['title']); ?></strong></p>
            <p><?php echo esc_html($ritmo_run['status']); ?> · <?php echo esc_html($ritmo_run['did']); ?></p>
            <?php if ($ritmo_run['lines'] !== []) : ?>
                <ul class="ritmo-list">
                    <?php foreach ($ritmo_run['lines'] as $ritmo_line) : ?><li><?php echo esc_html($ritmo_line); ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p><a href="<?php echo esc_url($ritmo_run['detail_url']); ?>"><?php esc_html_e('More about this run', 'ritmo-revenue'); ?></a>
            <?php if ($ritmo_run['shadow']) : ?> · <a href="<?php echo esc_url($ritmo_run['nights_url']); ?>"><?php esc_html_e('See what it would change', 'ritmo-revenue'); ?></a><?php endif; ?></p>
        <?php endif; ?>
    </section>

    <section class="ritmo-card" data-ritmo="card-next-runs">
        <h2><?php esc_html_e('Mode and next runs', 'ritmo-revenue'); ?></h2>
        <?php if (!$view['next']['ok']) : View::render('admin/screens/parts/card-error', $view['next']); else : ?>
            <p><span class="ritmo-badge ritmo-tone-info" data-ritmo="current-mode"><?php echo esc_html($view['next']['mode']); ?></span> <?php echo esc_html($view['next']['sentence']); ?> <a href="<?php echo esc_url($view['next']['operations_url']); ?>"><?php esc_html_e('Change', 'ritmo-revenue'); ?></a></p>
            <p><?php echo esc_html($view['next']['full']); ?></p>
            <p><?php echo esc_html($view['next']['light']); ?></p>
        <?php endif; ?>
        <?php if ($view['can_run']) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ritmo_run_shadow'); ?>
                <input type="hidden" name="action" value="ritmo_run_shadow">
                <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
                <button type="submit" class="button button-secondary" data-ritmo="run-shadow"
                        data-rest-url="<?php echo esc_url($view['rest_url'] . 'properties/' . rawurlencode($view['property_id']) . '/shadow-run'); ?>"
                        data-rest-nonce="<?php echo esc_attr($view['rest_nonce']); ?>"
                        data-text-running="<?php esc_attr_e('Running in shadow: reading and deciding, writing nothing…', 'ritmo-revenue'); ?>"
                        data-text-failed="<?php esc_attr_e('The shadow run did not finish:', 'ritmo-revenue'); ?>"><?php esc_html_e('Run in shadow now', 'ritmo-revenue'); ?></button>
                <span class="ritmo-progress" role="status" data-ritmo="progress"></span>
            </form>
        <?php endif; ?>
    </section>
</div>
