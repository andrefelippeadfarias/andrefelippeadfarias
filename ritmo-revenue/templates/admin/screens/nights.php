<?php
/**
 * Nights: the filters, the night detail when one is open, and the grid of rooms by nights.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

if ($view['empty']) : ?>
    <div class="ritmo-empty" data-ritmo="empty">
        <h2><?php esc_html_e('No property yet', 'ritmo-revenue'); ?></h2>
        <p><a class="button button-primary" href="<?php echo esc_url($view['setup_url']); ?>"><?php esc_html_e('Start the setup', 'ritmo-revenue'); ?></a></p>
    </div>
    <?php
    return;
endif;
$ritmo_f = $view['filters'];
$ritmo_detail = $view['detail'];
$ritmo_grid = $view['grid'];
?>
<details class="ritmo-filters" data-ritmo="grid-filters" data-ritmo-js="fold-narrow" open>
    <summary><?php esc_html_e('Filters', 'ritmo-revenue'); ?></summary>
    <form method="get" action="<?php echo esc_url($ritmo_f['action']); ?>">
        <input type="hidden" name="page" value="<?php echo esc_attr($ritmo_f['page']); ?>">
        <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_f['property']); ?>">
        <p>
            <label for="ritmo-from"><?php esc_html_e('From', 'ritmo-revenue'); ?></label>
            <input type="date" id="ritmo-from" name="from" value="<?php echo esc_attr($ritmo_f['from']); ?>">
        </p>
        <p>
            <label for="ritmo-nights"><?php esc_html_e('Nights', 'ritmo-revenue'); ?></label>
            <select id="ritmo-nights" name="nights">
                <?php foreach ($ritmo_f['windows'] as $ritmo_n) : ?>
                    <option value="<?php echo esc_attr((string) $ritmo_n); ?>" <?php selected($ritmo_n, $ritmo_f['nights']); ?>><?php echo esc_html(number_format_i18n($ritmo_n)); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="ritmo-show"><?php esc_html_e('Show', 'ritmo-revenue'); ?></label>
            <select id="ritmo-show" name="show">
                <?php foreach ($ritmo_f['show_options'] as $ritmo_value => $ritmo_label) : ?>
                    <option value="<?php echo esc_attr($ritmo_value); ?>" <?php selected($ritmo_value, $ritmo_f['show']); ?>><?php echo esc_html($ritmo_label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="ritmo-only"><?php esc_html_e('Only', 'ritmo-revenue'); ?></label>
            <select id="ritmo-only" name="only">
                <?php foreach ($ritmo_f['only_options'] as $ritmo_value => $ritmo_label) : ?>
                    <option value="<?php echo esc_attr($ritmo_value); ?>" <?php selected($ritmo_value, $ritmo_f['only']); ?>><?php echo esc_html($ritmo_label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <?php if ($ritmo_f['rooms'] !== []) : ?>
            <fieldset class="ritmo-rooms">
                <legend><?php esc_html_e('Rooms', 'ritmo-revenue'); ?></legend>
                <?php foreach ($ritmo_f['rooms'] as $ritmo_room) : ?>
                    <label><input type="checkbox" name="rooms[]" value="<?php echo esc_attr($ritmo_room['code']); ?>" <?php checked($ritmo_room['checked']); ?>> <?php echo esc_html($ritmo_room['name']); ?></label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
        <p class="ritmo-actions">
            <button type="submit" class="button"><?php esc_html_e('Apply', 'ritmo-revenue'); ?></button>
            <a href="<?php echo esc_url($ritmo_f['clear']); ?>"><?php esc_html_e('Clear', 'ritmo-revenue'); ?></a>
        </p>
    </form>
</details>

<?php if ($ritmo_detail !== null) : ?>
    <section id="ritmo-night-detail" class="ritmo-card ritmo-detail" data-ritmo="night-detail">
        <?php if (!$ritmo_detail['ok']) : ?>
            <h2 tabindex="-1"><?php esc_html_e('Night', 'ritmo-revenue'); ?></h2>
            <?php View::render('admin/screens/parts/card-error', $ritmo_detail); ?>
            <p><a href="<?php echo esc_url($ritmo_detail['close']); ?>"><?php esc_html_e('Close', 'ritmo-revenue'); ?></a></p>
        <?php else : ?>
            <h2 tabindex="-1" data-ritmo-focus><?php echo esc_html($ritmo_detail['title']); ?></h2>
            <p class="ritmo-muted"><?php echo esc_html($ritmo_detail['kind']); ?> · <?php echo esc_html($ritmo_detail['days_out']); ?></p>
            <p class="ritmo-actions">
                <a href="<?php echo esc_url($ritmo_detail['previous']); ?>"><?php esc_html_e('Previous night', 'ritmo-revenue'); ?></a>
                <a href="<?php echo esc_url($ritmo_detail['next']); ?>"><?php esc_html_e('Next night', 'ritmo-revenue'); ?></a>
                <a class="button" href="<?php echo esc_url($ritmo_detail['close']); ?>"><?php esc_html_e('Close', 'ritmo-revenue'); ?></a>
            </p>
            <div class="ritmo-cards">
                <div class="ritmo-block">
                    <h3><?php esc_html_e('Occupancy', 'ritmo-revenue'); ?></h3>
                    <p><?php echo esc_html($ritmo_detail['occupancy']); ?><?php if ($ritmo_detail['closed']) : ?> · <?php esc_html_e('closed in the PMS', 'ritmo-revenue'); ?><?php endif; ?></p>
                    <?php if ($ritmo_detail['bookings'] !== []) : ?>
                        <ul class="ritmo-list"><?php foreach ($ritmo_detail['bookings'] as $ritmo_booking) : ?><li><?php echo esc_html($ritmo_booking); ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </div>
                <div class="ritmo-block" data-ritmo="night-prices">
                    <h3><?php esc_html_e('Prices', 'ritmo-revenue'); ?></h3>
                    <dl class="ritmo-facts">
                        <?php foreach ($ritmo_detail['prices'] as $ritmo_price) : ?>
                            <dt><?php echo esc_html($ritmo_price['label']); ?></dt>
                            <dd><?php View::render('admin/screens/parts/price', $ritmo_price); ?></dd>
                        <?php endforeach; ?>
                    </dl>
                    <?php if ($ritmo_detail['price_note'] !== '') : ?><p class="ritmo-muted"><?php echo esc_html($ritmo_detail['price_note']); ?></p><?php endif; ?>
                </div>
                <div class="ritmo-block">
                    <h3><?php esc_html_e('Stay', 'ritmo-revenue'); ?></h3>
                    <ul class="ritmo-list"><?php foreach ($ritmo_detail['stay'] as $ritmo_line) : ?><li><?php echo esc_html($ritmo_line); ?></li><?php endforeach; ?></ul>
                </div>
                <div class="ritmo-block" data-ritmo="night-why">
                    <h3><?php esc_html_e('Why', 'ritmo-revenue'); ?></h3>
                    <p><strong><?php echo esc_html($ritmo_detail['why']['rule']); ?></strong></p>
                    <p><?php echo esc_html($ritmo_detail['why']['pace']); ?><?php if ($ritmo_detail['why']['status'] !== '') : ?> · <?php echo esc_html($ritmo_detail['why']['status']); ?><?php endif; ?></p>
                    <?php if ($ritmo_detail['why']['sentences'] !== []) : ?>
                        <ul class="ritmo-list" data-ritmo="reason"><?php foreach ($ritmo_detail['why']['sentences'] as $ritmo_sentence) : ?><li><?php echo esc_html($ritmo_sentence); ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <?php foreach ($ritmo_detail['why']['refused'] as $ritmo_refused) : ?><p class="ritmo-error"><?php echo esc_html($ritmo_refused); ?></p><?php endforeach; ?>
                    <?php if ($ritmo_detail['why']['orders'] !== []) : ?><p><?php esc_html_e('An owner order covers this night.', 'ritmo-revenue'); ?></p><?php endif; ?>
                </div>
                <?php if ($ritmo_detail['freeze'] !== null) : ?>
                    <div class="ritmo-block">
                        <h3><?php esc_html_e('Freeze', 'ritmo-revenue'); ?></h3>
                        <p><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php echo esc_html($ritmo_detail['freeze']['text']); ?></p>
                        <?php if ($ritmo_detail['freeze']['fields'] !== '') : ?><p class="ritmo-muted"><?php echo esc_html($ritmo_detail['freeze']['fields']); ?></p><?php endif; ?>
                        <?php if ($ritmo_detail['can_release']) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ritmo="freeze-release">
                                <?php wp_nonce_field('ritmo_freeze_release:' . $ritmo_detail['freeze']['id']); ?>
                                <input type="hidden" name="action" value="ritmo_freeze_release">
                                <input type="hidden" name="freeze_id" value="<?php echo esc_attr((string) $ritmo_detail['freeze']['id']); ?>">
                                <input type="hidden" name="property_id" value="<?php echo esc_attr($ritmo_detail['property_id']); ?>">
                                <input type="hidden" name="room" value="<?php echo esc_attr($ritmo_detail['room']); ?>">
                                <input type="hidden" name="night" value="<?php echo esc_attr($ritmo_detail['night']); ?>">
                                <p><label><input type="checkbox" name="understood" value="1" required> <?php esc_html_e('I understand the plugin may change this night again in the next run.', 'ritmo-revenue'); ?></label></p>
                                <button type="submit" class="button"><?php esc_html_e('Release the freeze', 'ritmo-revenue'); ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="ritmo-block ritmo-wide" data-ritmo="night-history">
                    <h3><?php esc_html_e('History', 'ritmo-revenue'); ?></h3>
                    <?php if ($ritmo_detail['history'] === []) : ?>
                        <p data-ritmo="empty"><?php esc_html_e('Nothing happened to this night yet.', 'ritmo-revenue'); ?></p>
                    <?php else : ?>
                        <ul class="ritmo-list"><?php foreach ($ritmo_detail['history'] as $ritmo_event) : ?><li><span class="ritmo-muted"><?php echo esc_html($ritmo_event['when']); ?></span> · <?php echo esc_html($ritmo_event['text']); ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </div>
            </div>
            <p class="ritmo-actions">
                <?php if ($ritmo_detail['can_order']) : ?><a class="button" href="<?php echo esc_url($ritmo_detail['order_url']); ?>"><?php esc_html_e('Create an order for this night', 'ritmo-revenue'); ?></a><?php endif; ?>
                <?php if ($ritmo_detail['can_undo']) : ?><a class="button" href="<?php echo esc_url($ritmo_detail['undo_url']); ?>"><?php esc_html_e('Undo a change of this night', 'ritmo-revenue'); ?></a><?php endif; ?>
            </p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="ritmo-card ritmo-wide">
    <?php if (!$ritmo_grid['ok']) : View::render('admin/screens/parts/card-error', $ritmo_grid); elseif ($ritmo_grid['empty_rooms']) : ?>
        <div class="ritmo-empty" data-ritmo="empty">
            <p><?php esc_html_e('No room to show: add the rooms in the setup, or tick at least one room in the filters.', 'ritmo-revenue'); ?></p>
            <p><a href="<?php echo esc_url($ritmo_grid['rooms_url']); ?>"><?php esc_html_e('Add the rooms', 'ritmo-revenue'); ?></a></p>
        </div>
    <?php else : ?>
        <nav class="ritmo-actions" aria-label="<?php esc_attr_e('Move through the nights', 'ritmo-revenue'); ?>">
            <a href="<?php echo esc_url($ritmo_grid['prev']); ?>" data-ritmo="grid-prev"><?php echo esc_html($ritmo_grid['prev_label']); ?></a>
            <a href="<?php echo esc_url($ritmo_grid['today']); ?>"><?php esc_html_e('Today', 'ritmo-revenue'); ?></a>
            <a href="<?php echo esc_url($ritmo_grid['next']); ?>" data-ritmo="grid-next"><?php echo esc_html($ritmo_grid['next_label']); ?></a>
            <?php foreach ($ritmo_grid['room_pages'] as $ritmo_rp) : ?>
                <a href="<?php echo esc_url($ritmo_rp['url']); ?>" <?php if ($ritmo_rp['current']) : ?>aria-current="page"<?php endif; ?>><?php echo esc_html($ritmo_rp['label']); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($ritmo_grid['no_run']) : ?>
            <p class="ritmo-muted" data-ritmo="empty"><?php esc_html_e('Run the first shadow run to see the plugin\'s prices and reasons. Occupancy is shown from the PMS calendar.', 'ritmo-revenue'); ?></p>
        <?php endif; ?>
        <p class="ritmo-muted"><span data-ritmo="grid-count"><?php echo esc_html(number_format_i18n($ritmo_grid['shown'])); ?></span> <?php esc_html_e('room nights shown', 'ritmo-revenue'); ?></p>
        <div class="ritmo-scroll ritmo-nightgrid-wrap" role="region" tabindex="0" aria-label="<?php esc_attr_e('Nights grid', 'ritmo-revenue'); ?>">
            <table class="ritmo-nightgrid" data-ritmo="grid">
                <caption><?php echo esc_html($ritmo_grid['caption']); ?></caption>
                <thead>
                    <tr>
                        <th scope="col" class="ritmo-sticky"><?php esc_html_e('Room', 'ritmo-revenue'); ?></th>
                        <?php foreach ($ritmo_grid['columns'] as $ritmo_col) : ?>
                            <th scope="col"<?php if ($ritmo_col['holiday'] !== '') : ?> class="ritmo-holiday"<?php endif; ?>>
                                <span class="ritmo-weekday"><?php echo esc_html($ritmo_col['weekday']); ?></span>
                                <span><?php echo esc_html($ritmo_col['day']); ?></span>
                                <?php if ($ritmo_col['holiday'] !== '') : ?><span class="ritmo-holiday-name"><?php echo esc_html($ritmo_col['holiday']); ?></span><?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ritmo_grid['rows'] as $ritmo_row) : ?>
                        <tr>
                            <th scope="row" class="ritmo-sticky"><?php echo esc_html($ritmo_row['name']); ?></th>
                            <?php foreach ($ritmo_row['cells'] as $ritmo_cell) : ?>
                                <?php if ($ritmo_cell === null) : ?>
                                    <td class="ritmo-cell-off"><span class="screen-reader-text"><?php esc_html_e('not shown by the filter', 'ritmo-revenue'); ?></span></td>
                                <?php else : ?>
                                    <td class="ritmo-cell ritmo-tone-<?php echo esc_attr($ritmo_cell['tone']); ?>">
                                        <a href="<?php echo esc_url($ritmo_cell['url']); ?>" aria-label="<?php echo esc_attr($ritmo_cell['label']); ?>" data-ritmo="grid-cell"<?php if ($ritmo_cell['would']) : ?> data-would-change="1"<?php endif; ?>>
                                            <span aria-hidden="true"><?php echo esc_html($ritmo_cell['text']); ?><?php echo esc_html($ritmo_cell['arrow']); ?></span>
                                            <?php if ($ritmo_cell['mark'] !== '') : ?><span class="ritmo-pace-mark" aria-hidden="true"><?php echo esc_html($ritmo_cell['mark']); ?></span><?php endif; ?>
                                            <?php if ($ritmo_cell['frozen']) : ?><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php endif; ?>
                                            <?php if ($ritmo_cell['order']) : ?><span class="dashicons dashicons-flag" aria-hidden="true"></span><?php endif; ?>
                                            <?php if ($ritmo_cell['alert']) : ?><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php endif; ?>
                                        </a>
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <ul class="ritmo-legend" data-ritmo="grid-legend" aria-label="<?php esc_attr_e('Legend of the grid', 'ritmo-revenue'); ?>">
            <li><span aria-hidden="true">↑</span> <?php esc_html_e('the plugin would raise the price range', 'ritmo-revenue'); ?></li>
            <li><span aria-hidden="true">↓</span> <?php esc_html_e('the plugin would lower the price range', 'ritmo-revenue'); ?></li>
            <li><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e('frozen: someone else changed the night', 'ritmo-revenue'); ?></li>
            <li><span class="dashicons dashicons-flag" aria-hidden="true"></span> <?php esc_html_e('an owner order covers the night', 'ritmo-revenue'); ?></li>
            <li><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php esc_html_e('an active alert', 'ritmo-revenue'); ?></li>
            <li><span class="ritmo-swatch ritmo-tone-good" aria-hidden="true"></span> <span aria-hidden="true">▲</span> <?php esc_html_e('ahead of pace', 'ritmo-revenue'); ?></li>
            <li><span class="ritmo-swatch ritmo-tone-info" aria-hidden="true"></span> <span aria-hidden="true">●</span> <?php esc_html_e('on pace', 'ritmo-revenue'); ?></li>
            <li><span class="ritmo-swatch ritmo-tone-attention" aria-hidden="true"></span> <span aria-hidden="true">▼ ■</span> <?php esc_html_e('behind pace or at the floor', 'ritmo-revenue'); ?></li>
            <li><span class="ritmo-swatch ritmo-tone-critical" aria-hidden="true"></span> <span aria-hidden="true">▼▼</span> <?php esc_html_e('far behind pace', 'ritmo-revenue'); ?></li>
            <li><span class="ritmo-swatch ritmo-tone-neutral" aria-hidden="true"></span> <?php esc_html_e('sold out or closed', 'ritmo-revenue'); ?></li>
        </ul>
    <?php endif; ?>
</section>
