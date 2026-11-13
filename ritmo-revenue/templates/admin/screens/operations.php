<?php
/**
 * Operations (admin spec 5.16): mode and switch, schedule, who did what.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$ritmo_post = admin_url('admin-post.php');
?>
<?php if ($view['empty']) : ?>
    <div class="ritmo-empty" data-ritmo="empty"><p><?php esc_html_e('No property yet. Create one in Settings, setup.', 'ritmo-revenue'); ?></p></div>
<?php elseif ($view['error'] !== null) : ?>
    <div class="notice notice-error inline" data-ritmo="error-ref" role="alert"><p><?php echo esc_html($view['error']); ?></p></div>
<?php elseif ($view['tab'] === 'schedule') : ?>
    <section class="ritmo-card ritmo-wide" data-ritmo="schedule">
        <h2><?php esc_html_e('When the plugin runs', 'ritmo-revenue'); ?></h2>
        <table class="widefat striped ritmo-table">
            <caption><?php /* translators: %s: time zone such as America/Sao_Paulo */ echo esc_html(sprintf(__('Daily runs, in the time zone %s', 'ritmo-revenue'), $view['timezone'])); ?></caption>
            <thead><tr><th scope="col"><?php esc_html_e('Time', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Kind', 'ritmo-revenue'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($view['times'] as $ritmo_time) : ?>
                    <tr><th scope="row" data-label="<?php esc_attr_e('Time', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_time['time']); ?></th><td data-label="<?php esc_attr_e('Kind', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_time['kind']); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><?php echo esc_html($view['next_full']); ?></p>
        <p><?php esc_html_e('A full run reads everything and decides every night; a light run only looks at what moved since the last run.', 'ritmo-revenue'); ?></p>
        <?php if ($view['can_edit']) : ?>
            <p><a class="button" href="<?php echo esc_url($view['rules_url']); ?>"><?php esc_html_e('Change the schedule in Rules', 'ritmo-revenue'); ?></a></p>
        <?php endif; ?>
    </section>
<?php elseif ($view['tab'] === 'events') : ?>
    <section class="ritmo-card ritmo-wide" data-ritmo="events">
        <h2><?php esc_html_e('Who did what', 'ritmo-revenue'); ?></h2>
        <?php if ($view['events'] === []) : ?>
            <p data-ritmo="empty"><?php esc_html_e('Nothing was changed yet.', 'ritmo-revenue'); ?></p>
        <?php else : ?>
            <table class="widefat striped ritmo-table">
                <caption><?php esc_html_e('The latest changes of mode, approvals, pauses, the emergency switch and the configuration', 'ritmo-revenue'); ?></caption>
                <thead><tr>
                    <th scope="col"><?php esc_html_e('When', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('What', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Who', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Why', 'ritmo-revenue'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($view['events'] as $ritmo_event) : ?>
                        <tr>
                            <th scope="row" data-label="<?php esc_attr_e('When', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_event['when']); ?></th>
                            <td data-label="<?php esc_attr_e('What', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_event['what']); ?></td>
                            <td data-label="<?php esc_attr_e('Who', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_event['who']); ?></td>
                            <td data-label="<?php esc_attr_e('Why', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_event['why'] === '' ? '—' : $ritmo_event['why']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
<?php else : ?>
    <p><?php esc_html_e('Plans waiting and the history of what was written have their own screens:', 'ritmo-revenue'); ?>
        <a href="<?php echo esc_url($view['approvals_url']); ?>"><?php esc_html_e('Approvals', 'ritmo-revenue'); ?></a> ·
        <a href="<?php echo esc_url($view['changes_url']); ?>"><?php esc_html_e('Change log', 'ritmo-revenue'); ?></a></p>
    <div class="ritmo-cards">
        <section class="ritmo-card" data-ritmo="mode">
            <h2><?php esc_html_e('Mode', 'ritmo-revenue'); ?></h2>
            <p><span class="ritmo-badge ritmo-tone-info" data-ritmo="current-mode" data-mode="<?php echo esc_attr($view['mode']['value']); ?>"><span class="dashicons dashicons-visibility" aria-hidden="true"></span> <?php echo esc_html($view['mode']['label']); ?></span></p>
            <p><?php echo esc_html($view['mode']['sentence']); ?></p>
            <?php if ($view['checklist']['total'] > 0) : ?>
                <h3><?php /* translators: 1: conditions met, 2: conditions in all, 3: the next mode */ echo esc_html(sprintf(__('%1$s of %2$s conditions met for the %3$s mode', 'ritmo-revenue'), number_format_i18n($view['checklist']['done']), number_format_i18n($view['checklist']['total']), $view['checklist']['target'])); ?></h3>
                <ul class="ritmo-checklist" data-ritmo="preconditions">
                    <?php foreach ($view['checklist']['items'] as $ritmo_item) : ?>
                        <li class="<?php echo esc_attr($ritmo_item['met'] ? 'ritmo-met' : 'ritmo-unmet'); ?>">
                            <span class="dashicons <?php echo esc_attr($ritmo_item['met'] ? 'dashicons-yes' : 'dashicons-minus'); ?>" aria-hidden="true"></span>
                            <span class="screen-reader-text"><?php echo esc_html($ritmo_item['met'] ? __('Done:', 'ritmo-revenue') : __('Missing:', 'ritmo-revenue')); ?></span>
                            <?php echo esc_html($ritmo_item['text']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php foreach ($view['checklist']['blockers'] as $ritmo_blocker) : ?>
                <p class="ritmo-unmet"><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php echo esc_html($ritmo_blocker); ?></p>
            <?php endforeach; ?>
            <?php if ($view['can_change_mode']) : ?>
                <form method="post" action="<?php echo esc_url($ritmo_post); ?>" class="ritmo-form">
                    <?php wp_nonce_field('ritmo_set_mode'); ?>
                    <input type="hidden" name="action" value="ritmo_set_mode">
                    <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
                    <p><label for="ritmo-mode"><?php esc_html_e('New mode', 'ritmo-revenue'); ?></label>
                        <select id="ritmo-mode" name="mode">
                            <option value="shadow"><?php esc_html_e('Shadow', 'ritmo-revenue'); ?></option>
                            <option value="approval"><?php esc_html_e('Approval', 'ritmo-revenue'); ?></option>
                            <?php if ($view['autonomous_enabled']) : ?>
                                <option value="auto"><?php esc_html_e('Autonomous', 'ritmo-revenue'); ?></option>
                            <?php endif; ?>
                        </select></p>
                    <?php if ($view['autonomous_enabled']) : ?>
                        <p><label for="ritmo-confirmation"><?php esc_html_e('To switch to autonomous, type the property id', 'ritmo-revenue'); ?></label>
                            <input type="text" id="ritmo-confirmation" name="confirmation" autocomplete="off" class="regular-text"></p>
                    <?php endif; ?>
                    <p><label for="ritmo-reason"><?php esc_html_e('Why (optional)', 'ritmo-revenue'); ?></label>
                        <input type="text" id="ritmo-reason" name="reason" class="regular-text" maxlength="190"></p>
                    <button type="submit" class="button" data-ritmo="set-mode"><?php esc_html_e('Change mode', 'ritmo-revenue'); ?></button>
                </form>
            <?php endif; ?>
        </section>

        <section class="ritmo-card" data-ritmo="run-now">
            <h2><?php esc_html_e('Runs', 'ritmo-revenue'); ?></h2>
            <?php if ($view['last_run'] === null) : ?>
                <p data-ritmo="empty"><?php esc_html_e('No run yet.', 'ritmo-revenue'); ?></p>
            <?php else : ?>
                <p data-ritmo="last-run-status"><strong><?php echo esc_html($view['last_run']['title']); ?></strong></p>
                <?php if ($view['last_run']['lines'] !== []) : ?>
                    <ul class="ritmo-lines" data-ritmo="report">
                        <?php foreach ($view['last_run']['lines'] as $ritmo_line) : ?>
                            <li><?php echo esc_html($ritmo_line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($view['paused']) : ?>
                <p class="ritmo-unmet" data-ritmo="paused"><span class="dashicons dashicons-controls-pause" aria-hidden="true"></span> <?php echo esc_html($view['paused_text']); ?></p>
            <?php endif; ?>
            <?php if ($view['can_run']) : ?>
                <form method="post" action="<?php echo esc_url($ritmo_post); ?>">
                    <?php wp_nonce_field('ritmo_run_now'); ?>
                    <input type="hidden" name="action" value="ritmo_run_now">
                    <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
                    <button type="submit" class="button button-primary" data-ritmo="run-now-button"><?php esc_html_e('Run now', 'ritmo-revenue'); ?></button>
                </form>
                <?php if ($view['paused']) : ?>
                    <form method="post" action="<?php echo esc_url($ritmo_post); ?>">
                        <?php wp_nonce_field('ritmo_resume'); ?>
                        <input type="hidden" name="action" value="ritmo_resume">
                        <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
                        <button type="submit" class="button" data-ritmo="resume"><?php esc_html_e('Resume the scheduled runs', 'ritmo-revenue'); ?></button>
                    </form>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url($ritmo_post); ?>" class="ritmo-form">
                        <?php wp_nonce_field('ritmo_pause'); ?>
                        <input type="hidden" name="action" value="ritmo_pause">
                        <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
                        <p><label for="ritmo-pause-hours"><?php esc_html_e('Pause the scheduled runs for', 'ritmo-revenue'); ?></label>
                            <select id="ritmo-pause-hours" name="hours">
                                <?php foreach ([1, 2, 4, 8, 24, 72, 168] as $ritmo_hours) : ?>
                                    <option value="<?php echo esc_attr((string) $ritmo_hours); ?>"><?php /* translators: %s: number of hours */ echo esc_html(sprintf(_n('%s hour', '%s hours', $ritmo_hours, 'ritmo-revenue'), number_format_i18n($ritmo_hours))); ?></option>
                                <?php endforeach; ?>
                                <option value="0"><?php esc_html_e('until someone resumes them', 'ritmo-revenue'); ?></option>
                            </select></p>
                        <p><label for="ritmo-pause-reason"><?php esc_html_e('Why (optional)', 'ritmo-revenue'); ?></label>
                            <input type="text" id="ritmo-pause-reason" name="reason" class="regular-text" maxlength="190"></p>
                        <button type="submit" class="button" data-ritmo="pause"><?php esc_html_e('Pause', 'ritmo-revenue'); ?></button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="ritmo-card" data-ritmo="emergency">
            <h2><?php esc_html_e('Emergency switch', 'ritmo-revenue'); ?></h2>
            <p data-ritmo="switch-state"><?php echo esc_html($view['switch']['engaged'] ? __('Engaged: nothing is written; scheduled runs only read and watch.', 'ritmo-revenue') : __('Released: the plugin works as its mode says.', 'ritmo-revenue')); ?></p>
            <?php if ($view['switch']['engaged'] && $view['switch']['by_constant']) : ?>
                <p class="description"><?php esc_html_e('The constant RITMO_KILL_SWITCH in wp-config.php keeps it engaged; only removing it there releases it.', 'ritmo-revenue'); ?></p>
            <?php endif; ?>
            <?php if (($view['switch']['engaged'] && $view['switch']['may_release']) || (!$view['switch']['engaged'] && $view['switch']['may_engage'])) : ?>
                <form method="post" action="<?php echo esc_url($ritmo_post); ?>">
                    <?php wp_nonce_field('ritmo_emergency_operations'); ?>
                    <input type="hidden" name="action" value="ritmo_emergency_operations">
                    <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
                    <input type="hidden" name="switch" value="<?php echo esc_attr($view['switch']['engaged'] ? 'release' : 'engage'); ?>">
                    <button type="submit" class="button<?php echo esc_attr($view['switch']['engaged'] ? '' : ' button-link-delete'); ?>" data-ritmo="switch-button"><?php echo esc_html($view['switch']['engaged'] ? __('Release the switch', 'ritmo-revenue') : __('Engage the switch: stop every write', 'ritmo-revenue')); ?></button>
                </form>
            <?php elseif ($view['switch']['engaged']) : ?>
                <p class="description"><?php esc_html_e('Only a person who manages the connections can release it.', 'ritmo-revenue'); ?></p>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
