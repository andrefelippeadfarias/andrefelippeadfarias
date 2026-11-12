<?php
/**
 * Read-only dashboard of one property.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\ReasonText;
use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_board = $view['board'];
$ritmo_signal_text = [
    'green' => __('On or above the goal', 'ritmo-revenue'),
    'amber' => __('Slightly under the goal', 'ritmo-revenue'),
    'red' => __('Well under the goal', 'ritmo-revenue'),
    'unknown' => __('No calendar read yet', 'ritmo-revenue'),
];
$ritmo_percent = static fn ($value): string => $value === null ? '–' : number_format_i18n((float) $value, 1) . '%';
$ritmo_amount = static fn ($value): string => $value === null ? '–' : number_format_i18n((float) $value, 2);
$ritmo_time = static fn ($instant): string => $instant instanceof DateTimeInterface ? wp_date('Y-m-d H:i', $instant->getTimestamp()) : '–';

View::render('admin/partials/header', $view);
?>
<?php if ($ritmo_board === null) : ?>
    <div class="ritmo-card">
        <p><?php esc_html_e('No property yet. Create one and map its room types on the Setup screen.', 'ritmo-revenue'); ?></p>
    </div>
<?php else : ?>
    <?php $ritmo_property = $ritmo_board['property']; ?>
    <?php $ritmo_occupancy = $ritmo_board['occupancy']; ?>
    <div class="ritmo-bar">
        <?php if (count($view['properties']) > 1) : ?>
            <form method="get" class="ritmo-inline">
                <input type="hidden" name="page" value="<?php echo esc_attr($view['page']); ?>">
                <label for="ritmo-property"><?php esc_html_e('Property', 'ritmo-revenue'); ?></label>
                <select id="ritmo-property" name="property" onchange="this.form.submit()">
                    <?php foreach ($view['properties'] as $ritmo_option) : ?>
                        <option value="<?php echo esc_attr($ritmo_option['id']); ?>" <?php selected($ritmo_option['id'], $view['selected']); ?>><?php echo esc_html($ritmo_option['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <h2 class="ritmo-property-name"><?php echo esc_html($ritmo_property['name']); ?></h2>
        <span class="ritmo-badge ritmo-badge-shadow"><?php esc_html_e('Shadow mode: nothing is written', 'ritmo-revenue'); ?></span>
    </div>

    <div class="ritmo-grid">
        <section class="ritmo-card ritmo-signal ritmo-signal-<?php echo esc_attr($ritmo_occupancy['signal']); ?>" data-ritmo="signal">
            <h3><?php esc_html_e('Occupancy, next 7 nights', 'ritmo-revenue'); ?></h3>
            <?php $ritmo_seven = $ritmo_occupancy['property'][7] ?? null; ?>
            <p class="ritmo-big"><span class="ritmo-light" aria-hidden="true"></span><?php echo esc_html($ritmo_percent($ritmo_seven['percent'] ?? null)); ?></p>
            <p>
                <?php
                /* translators: %s: occupancy goal in percent */
                echo esc_html(sprintf(__('Goal %s', 'ritmo-revenue'), $ritmo_percent($ritmo_seven['goal'] ?? null)));
                ?>
                · <?php echo esc_html($ritmo_signal_text[$ritmo_occupancy['signal']] ?? ''); ?>
            </p>
            <p class="description">
                <?php
                /* translators: %s: date and time of the last calendar read */
                echo esc_html(sprintf(__('PMS calendar read at %s', 'ritmo-revenue'), $ritmo_occupancy['captured_at'] === null ? '–' : wp_date('Y-m-d H:i', strtotime($ritmo_occupancy['captured_at']))));
                ?>
            </p>
        </section>

        <section class="ritmo-card" data-ritmo="last-run">
            <h3><?php esc_html_e('Last shadow run', 'ritmo-revenue'); ?></h3>
            <?php $ritmo_run = $ritmo_board['last_run']; ?>
            <?php if ($ritmo_run === null) : ?>
                <p><?php esc_html_e('No shadow run yet.', 'ritmo-revenue'); ?></p>
            <?php else : ?>
                <p><span class="ritmo-status ritmo-status-<?php echo esc_attr($ritmo_run['status']); ?>" data-ritmo="run-status"><?php echo esc_html($ritmo_run['status']); ?></span>
                    #<?php echo esc_html((string) $ritmo_run['id']); ?> · <?php echo esc_html($ritmo_time($ritmo_run['started_at'])); ?></p>
                <?php if ($ritmo_run['error'] !== null) : ?>
                    <p class="ritmo-error"><?php echo esc_html($ritmo_run['error']); ?></p>
                <?php endif; ?>
                <?php $ritmo_summary = $ritmo_board['last_completed']['summary'] ?? []; ?>
                <?php if ($ritmo_summary !== []) : ?>
                    <ul class="ritmo-facts">
                        <li><?php echo esc_html(sprintf(/* translators: %d: room nights */ __('%d room nights decided', 'ritmo-revenue'), (int) ($ritmo_summary['decide']['nights'] ?? 0))); ?></li>
                        <li><?php echo esc_html(sprintf(/* translators: %d: room nights */ __('%d without market data', 'ritmo-revenue'), (int) ($ritmo_summary['decide']['no_market_data'] ?? 0))); ?></li>
                        <li><?php echo esc_html(sprintf(/* translators: %d: items */ __('%d would be written', 'ritmo-revenue'), (int) ($ritmo_summary['validate']['would_write'] ?? 0))); ?></li>
                        <li><?php echo esc_html(sprintf(/* translators: %d: items */ __('%d refused by the validator', 'ritmo-revenue'), (int) ($ritmo_summary['validate']['rejected'] ?? 0))); ?></li>
                        <li><?php echo esc_html(sprintf(/* translators: %d: requests */ __('%d writes sent', 'ritmo-revenue'), (int) ($ritmo_summary['shadow']['writes_sent'] ?? 0))); ?></li>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($view['can_run']) : ?>
                <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-run-form">
                    <?php wp_nonce_field('ritmo_run_shadow'); ?>
                    <input type="hidden" name="action" value="ritmo_run_shadow">
                    <input type="hidden" name="property_id" value="<?php echo esc_attr($ritmo_property['id']); ?>">
                    <button type="submit" class="button button-primary" data-ritmo="run-shadow"
                        data-rest-url="<?php echo esc_url($view['rest_url'] . 'properties/' . rawurlencode($ritmo_property['id']) . '/shadow-run'); ?>"
                        data-rest-nonce="<?php echo esc_attr($view['rest_nonce']); ?>"
                        data-text-running="<?php esc_attr_e('Running the shadow run…', 'ritmo-revenue'); ?>"
                        data-text-failed="<?php esc_attr_e('The shadow run could not finish:', 'ritmo-revenue'); ?>"
                        <?php disabled($view['switch_engaged']); ?>><?php esc_html_e('Run shadow now', 'ritmo-revenue'); ?></button>
                    <span class="ritmo-progress" data-ritmo="progress" aria-live="polite"></span>
                </form>
                <?php if ($view['switch_engaged']) : ?>
                    <p class="ritmo-error"><?php esc_html_e('The emergency switch is engaged: no run starts.', 'ritmo-revenue'); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <section class="ritmo-card">
        <h3><?php esc_html_e('Occupancy by room and window', 'ritmo-revenue'); ?></h3>
        <table class="widefat striped ritmo-table" data-ritmo="windows">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Room type', 'ritmo-revenue'); ?></th>
                    <?php foreach (array_keys($ritmo_occupancy['property']) as $ritmo_days) : ?>
                        <th scope="col"><?php echo esc_html(sprintf(/* translators: %d: days */ __('%d nights', 'ritmo-revenue'), (int) $ritmo_days)); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach (['' => $ritmo_occupancy['property']] + $ritmo_occupancy['rooms'] as $ritmo_code => $ritmo_windows) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($ritmo_code === '' ? __('Whole property', 'ritmo-revenue') : (string) $ritmo_code); ?></th>
                        <?php foreach ($ritmo_windows as $ritmo_window) : ?>
                            <td class="ritmo-cell-<?php echo esc_attr($ritmo_window['signal']); ?>">
                                <?php echo esc_html($ritmo_percent($ritmo_window['percent'])); ?>
                                <small><?php echo esc_html(sprintf(/* translators: %s: goal */ __('goal %s', 'ritmo-revenue'), $ritmo_percent($ritmo_window['goal']))); ?></small>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <div class="ritmo-grid">
        <section class="ritmo-card" data-ritmo="alerts">
            <h3><?php esc_html_e('Alerts', 'ritmo-revenue'); ?></h3>
            <?php if ($ritmo_board['alerts'] === []) : ?>
                <p><?php esc_html_e('No open alert.', 'ritmo-revenue'); ?></p>
            <?php else : ?>
                <ul class="ritmo-alerts">
                    <?php foreach ($ritmo_board['alerts'] as $ritmo_alert) : ?>
                        <li class="ritmo-alert-<?php echo esc_attr($ritmo_alert['severity']); ?>">
                            <strong><?php echo esc_html($ritmo_alert['room_code'] !== '' ? $ritmo_alert['room_code'] : $ritmo_property['id']); ?></strong>
                            <?php if ($ritmo_alert['night'] instanceof DateTimeInterface) : ?>
                                <?php echo esc_html($ritmo_alert['night']->format('Y-m-d')); ?>
                            <?php endif; ?>
                            — <?php echo esc_html($ritmo_alert['message']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="ritmo-card" data-ritmo="push">
            <h3><?php esc_html_e('Price push to the PMS', 'ritmo-revenue'); ?></h3>
            <?php if ($ritmo_board['push'] === []) : ?>
                <p><?php esc_html_e('Known after the first shadow run.', 'ritmo-revenue'); ?></p>
            <?php else : ?>
                <table class="widefat striped ritmo-table">
                    <thead><tr>
                        <th scope="col"><?php esc_html_e('Room type', 'ritmo-revenue'); ?></th>
                        <th scope="col"><?php esc_html_e('Push', 'ritmo-revenue'); ?></th>
                        <th scope="col"><?php esc_html_e('Last push', 'ritmo-revenue'); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($ritmo_board['push'] as $ritmo_code => $ritmo_push) : ?>
                            <tr class="<?php echo esc_attr($ritmo_push['problem'] === null ? '' : 'ritmo-row-warning'); ?>">
                                <th scope="row"><?php echo esc_html((string) $ritmo_code); ?></th>
                                <td><?php echo esc_html($ritmo_push['push_enabled'] ? __('On', 'ritmo-revenue') : __('Off', 'ritmo-revenue')); ?></td>
                                <td><?php echo esc_html($ritmo_push['last_pushed_at'] === null ? __('never', 'ritmo-revenue') : sprintf(/* translators: %d: hours */ __('%d hours ago', 'ritmo-revenue'), (int) $ritmo_push['hours_since_push'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>

    <section class="ritmo-card" data-ritmo="would-write">
        <h3><?php esc_html_e('What would be written', 'ritmo-revenue'); ?></h3>
        <p class="description"><?php esc_html_e('Floors and ceilings the last completed shadow run would send to the pricing system, with the reason. Nothing was sent.', 'ritmo-revenue'); ?></p>
        <?php if ($ritmo_board['would_write'] === []) : ?>
            <p><?php esc_html_e('Nothing to write.', 'ritmo-revenue'); ?></p>
        <?php else : ?>
            <p class="description" data-ritmo="would-write-total">
                <?php
                /* translators: 1: room nights shown, 2: room nights in total */
                echo esc_html(sprintf(__('%1$d of %2$d room nights shown, the nearest first.', 'ritmo-revenue'), count($ritmo_board['would_write']), $ritmo_board['would_write_total']));
                ?>
            </p>
            <table class="widefat striped ritmo-table ritmo-would-write">
                <thead><tr>
                    <th scope="col"><?php esc_html_e('Night', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Room type', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Price now', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Floor: in force → would be', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Ceiling: in force → would be', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Why', 'ritmo-revenue'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($ritmo_board['would_write'] as $ritmo_item) : ?>
                        <tr>
                            <td><?php echo esc_html($ritmo_item['night']->format('Y-m-d')); ?></td>
                            <td><?php echo esc_html($ritmo_item['room_code']); ?></td>
                            <td><?php echo esc_html($ritmo_amount($ritmo_item['current_price'])); ?></td>
                            <?php foreach (['floor', 'ceiling'] as $ritmo_field) : ?>
                                <td>
                                    <?php if ($ritmo_item[$ritmo_field] === null) : ?>
                                        –
                                    <?php else : ?>
                                        <?php echo esc_html($ritmo_amount($ritmo_item[$ritmo_field]['current'])); ?> →
                                        <strong><?php echo esc_html($ritmo_amount($ritmo_item[$ritmo_field]['proposed'])); ?></strong>
                                        <?php if ($ritmo_item[$ritmo_field]['percent'] !== null) : ?>
                                            <small><?php echo esc_html(sprintf(/* translators: %+d: percentage over the listing base */ __('%+d%% over the base', 'ritmo-revenue'), (int) $ritmo_item[$ritmo_field]['percent'])); ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="ritmo-why"><?php echo esc_html(ReasonText::describe($ritmo_item['rule_trace'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php View::render('admin/partials/footer', $view); ?>
