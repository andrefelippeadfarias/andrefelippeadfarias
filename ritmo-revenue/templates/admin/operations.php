<?php
/**
 * Operations: mode of the property, the plan waiting for approval, the change log with undo,
 * "Run now", the last run's report and the emergency switch.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;
use Ritmo\Application\Approval\PlanEntry;
use Ritmo\Domain\Write\Change\ChangeRecord;

defined('ABSPATH') || exit;

$ritmo_mode_text = [
    'shadow' => __('Shadow: computes and shows what it would write; writes nothing.', 'ritmo-revenue'),
    'approval' => __('Approval: writes only what a person approved.', 'ritmo-revenue'),
    'auto' => __('Autonomous: writes on its own, inside the safety limits.', 'ritmo-revenue'),
];
$ritmo_value = static fn ($value): string => $value === null ? '–' : (string) $value;

View::render('admin/partials/header', $view);
?>
<?php if ($view['properties'] === []) : ?>
    <p><?php esc_html_e('No property yet: create one in Setup.', 'ritmo-revenue'); ?></p>
<?php else : ?>
    <form method="get" class="ritmo-property-picker">
        <input type="hidden" name="page" value="<?php echo esc_attr($view['page']); ?>">
        <label for="ritmo-property"><?php esc_html_e('Property', 'ritmo-revenue'); ?></label>
        <select id="ritmo-property" name="property" onchange="this.form.submit()">
            <?php foreach ($view['properties'] as $ritmo_option) : ?>
                <option value="<?php echo esc_attr($ritmo_option['id']); ?>"<?php selected($ritmo_option['id'], $view['selected']); ?>><?php echo esc_html($ritmo_option['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="ritmo-grid">
        <section class="ritmo-card" data-ritmo="mode">
            <h2><?php esc_html_e('Mode', 'ritmo-revenue'); ?></h2>
            <p data-ritmo="current-mode" data-mode="<?php echo esc_attr((string) $view['mode']); ?>"><?php echo esc_html($ritmo_mode_text[$view['mode']] ?? ''); ?></p>
            <form method="post" action="<?php echo esc_url($view['post_url']); ?>">
                <?php wp_nonce_field('ritmo_set_mode'); ?>
                <input type="hidden" name="action" value="ritmo_set_mode">
                <input type="hidden" name="property_id" value="<?php echo esc_attr((string) $view['selected']); ?>">
                <label for="ritmo-mode"><?php esc_html_e('New mode', 'ritmo-revenue'); ?></label>
                <select id="ritmo-mode" name="mode">
                    <option value="shadow"><?php esc_html_e('Shadow', 'ritmo-revenue'); ?></option>
                    <option value="approval"><?php esc_html_e('Approval', 'ritmo-revenue'); ?></option>
                    <?php if ($view['autonomous_enabled']) : ?>
                        <option value="auto"><?php esc_html_e('Autonomous', 'ritmo-revenue'); ?></option>
                    <?php endif; ?>
                </select>
                <?php if ($view['autonomous_enabled']) : ?>
                    <p>
                        <label for="ritmo-confirmation"><?php esc_html_e('To switch to autonomous, type the property id:', 'ritmo-revenue'); ?></label>
                        <input type="text" id="ritmo-confirmation" name="confirmation" autocomplete="off" class="regular-text">
                    </p>
                <?php endif; ?>
                <p><label for="ritmo-reason"><?php esc_html_e('Why', 'ritmo-revenue'); ?></label>
                    <input type="text" id="ritmo-reason" name="reason" class="regular-text" maxlength="200"></p>
                <button type="submit" class="button" data-ritmo="set-mode"><?php esc_html_e('Change mode', 'ritmo-revenue'); ?></button>
            </form>
        </section>

        <section class="ritmo-card" data-ritmo="run-now">
            <h2><?php esc_html_e('Run now', 'ritmo-revenue'); ?></h2>
            <?php if ($view['last_run'] !== null) : ?>
                <p data-ritmo="last-run-status"><?php echo esc_html(sprintf(/* translators: 1: run id, 2: kind, 3: status */ __('Last run #%1$d (%2$s): %3$s', 'ritmo-revenue'), $view['last_run']['id'], $view['last_run']['kind'], $view['last_run']['status'])); ?></p>
                <ul class="ritmo-facts" data-ritmo="report">
                    <?php foreach ($view['report'] as $ritmo_line) : ?>
                        <li><?php echo esc_html((string) $ritmo_line); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($view['can_run']) : ?>
                <form method="post" action="<?php echo esc_url($view['post_url']); ?>">
                    <?php wp_nonce_field('ritmo_run_now'); ?>
                    <input type="hidden" name="action" value="ritmo_run_now">
                    <input type="hidden" name="property_id" value="<?php echo esc_attr((string) $view['selected']); ?>">
                    <button type="submit" class="button button-primary" data-ritmo="run-now-button"><?php esc_html_e('Run now', 'ritmo-revenue'); ?></button>
                </form>
            <?php endif; ?>
        </section>

        <section class="ritmo-card" data-ritmo="emergency">
            <h2><?php esc_html_e('Emergency switch', 'ritmo-revenue'); ?></h2>
            <p data-ritmo="switch-state"><?php echo esc_html($view['switch_engaged'] ? __('Engaged: nothing is written; scheduled runs only read and watch.', 'ritmo-revenue') : __('Released.', 'ritmo-revenue')); ?></p>
            <?php if ($view['can_manage']) : ?>
                <form method="post" action="<?php echo esc_url($view['post_url']); ?>">
                    <?php wp_nonce_field('ritmo_emergency_operations'); ?>
                    <input type="hidden" name="action" value="ritmo_emergency_operations">
                    <input type="hidden" name="switch" value="<?php echo esc_attr($view['switch_engaged'] ? 'release' : 'engage'); ?>">
                    <button type="submit" class="button<?php echo esc_attr($view['switch_engaged'] ? '' : ' button-link-delete'); ?>" data-ritmo="switch-button"><?php echo esc_html($view['switch_engaged'] ? __('Release the switch', 'ritmo-revenue') : __('Engage the switch: stop every write', 'ritmo-revenue')); ?></button>
                </form>
            <?php endif; ?>
        </section>
    </div>

    <section class="ritmo-card" data-ritmo="approvals">
        <h2><?php esc_html_e('Waiting for approval', 'ritmo-revenue'); ?></h2>
        <?php if ($view['pending'] === []) : ?>
            <p><?php esc_html_e('Nothing waits for approval.', 'ritmo-revenue'); ?></p>
        <?php endif; ?>
        <?php foreach ($view['pending'] as $ritmo_request) : ?>
            <form method="post" action="<?php echo esc_url($view['post_url']); ?>" data-ritmo="approval-request" data-request="<?php echo esc_attr((string) $ritmo_request['id']); ?>">
                <?php wp_nonce_field('ritmo_decide_plan'); ?>
                <input type="hidden" name="action" value="ritmo_decide_plan">
                <input type="hidden" name="property_id" value="<?php echo esc_attr((string) $view['selected']); ?>">
                <input type="hidden" name="request_id" value="<?php echo esc_attr((string) $ritmo_request['id']); ?>">
                <p><?php echo esc_html(sprintf(/* translators: 1: kind, 2: day, 3: expiry */ __('Request of kind %1$s for %2$s; valid until %3$s.', 'ritmo-revenue'), $ritmo_request['kind'], $ritmo_request['plan_day'], wp_date('Y-m-d H:i', strtotime($ritmo_request['expires_at'])))); ?></p>
                <table class="widefat striped ritmo-table">
                    <thead><tr>
                        <th scope="col"></th><th scope="col"><?php esc_html_e('Entry', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Room', 'ritmo-revenue'); ?></th>
                        <th scope="col"><?php esc_html_e('Night or value', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Fields sent', 'ritmo-revenue'); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($ritmo_request['entries'] as $ritmo_entry) : ?>
                            <?php /** @var PlanEntry $ritmo_entry */ ?>
                            <tr>
                                <td><input type="checkbox" name="entries[]" value="<?php echo esc_attr($ritmo_entry->key()); ?>" <?php disabled(!$view['can_approve']); ?>></td>
                                <td><?php echo esc_html($ritmo_entry->kind); ?></td>
                                <td><?php echo esc_html($ritmo_entry->roomCode); ?></td>
                                <td><?php echo esc_html($ritmo_entry->subject); ?></td>
                                <td><code><?php echo esc_html((string) wp_json_encode($ritmo_entry->fields)); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($view['can_approve']) : ?>
                    <p>
                        <label><?php esc_html_e('Note', 'ritmo-revenue'); ?> <input type="text" name="note" maxlength="200" class="regular-text"></label>
                        <button type="submit" name="decision" value="all" class="button button-primary" data-ritmo="approve-all"><?php esc_html_e('Approve everything', 'ritmo-revenue'); ?></button>
                        <button type="submit" name="decision" value="selected" class="button" data-ritmo="approve-selected"><?php esc_html_e('Approve the nights ticked', 'ritmo-revenue'); ?></button>
                        <button type="submit" name="decision" value="reject" class="button button-link-delete" data-ritmo="reject"><?php esc_html_e('Refuse', 'ritmo-revenue'); ?></button>
                    </p>
                <?php endif; ?>
            </form>
        <?php endforeach; ?>
    </section>

    <section class="ritmo-card" data-ritmo="change-log">
        <h2><?php esc_html_e('Change log', 'ritmo-revenue'); ?></h2>
        <?php if ($view['changes'] === []) : ?>
            <p><?php esc_html_e('Nothing was written yet.', 'ritmo-revenue'); ?></p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url($view['post_url']); ?>">
                <?php wp_nonce_field('ritmo_undo_changes'); ?>
                <input type="hidden" name="action" value="ritmo_undo_changes">
                <input type="hidden" name="property_id" value="<?php echo esc_attr((string) $view['selected']); ?>">
                <table class="widefat striped ritmo-table">
                    <thead><tr>
                        <th scope="col"></th><th scope="col"><?php esc_html_e('When', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Room', 'ritmo-revenue'); ?></th>
                        <th scope="col"><?php esc_html_e('Night', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Field', 'ritmo-revenue'); ?></th>
                        <th scope="col"><?php esc_html_e('Before', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('After', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Who', 'ritmo-revenue'); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($view['changes'] as $ritmo_change) : ?>
                            <?php /** @var ChangeRecord $ritmo_change */ ?>
                            <tr data-ritmo="change" class="<?php echo esc_attr($ritmo_change->undoneAt === null ? '' : 'ritmo-undone'); ?>">
                                <td><input type="checkbox" name="changes[]" value="<?php echo esc_attr((string) $ritmo_change->id); ?>" <?php disabled(!$view['can_approve'] || $ritmo_change->undoneAt !== null); ?>></td>
                                <td><?php echo esc_html(wp_date('Y-m-d H:i', $ritmo_change->createdAt->getTimestamp())); ?></td>
                                <td><?php echo esc_html($ritmo_change->roomCode); ?></td>
                                <td><?php echo esc_html($ritmo_change->night?->format('Y-m-d') ?? '–'); ?></td>
                                <td><?php echo esc_html($ritmo_change->field); ?></td>
                                <td><?php echo esc_html($ritmo_value($ritmo_change->oldValue)); ?></td>
                                <td><?php echo esc_html($ritmo_value($ritmo_change->newValue)); ?></td>
                                <td><?php echo esc_html($ritmo_change->actor); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($view['can_approve']) : ?>
                    <p><button type="submit" class="button" data-ritmo="undo"><?php esc_html_e('Undo the changes ticked', 'ritmo-revenue'); ?></button>
                        <span class="description"><?php esc_html_e('The undo is a plan: it is validated, approved by you and written through the write gate. A night that changed since is left alone.', 'ritmo-revenue'); ?></span></p>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </section>

    <section class="ritmo-card" data-ritmo="events">
        <h2><?php esc_html_e('Who did what', 'ritmo-revenue'); ?></h2>
        <ul class="ritmo-facts">
            <?php foreach ($view['events'] as $ritmo_event) : ?>
                <li><?php echo esc_html(sprintf('%s · %s: %s → %s · %s · %s', wp_date('Y-m-d H:i', $ritmo_event['at']->getTimestamp()), $ritmo_event['kind'], $ritmo_event['from'], $ritmo_event['to'], $ritmo_event['actor'], $ritmo_event['reason'])); ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
<?php
View::render('admin/partials/footer', $view);
