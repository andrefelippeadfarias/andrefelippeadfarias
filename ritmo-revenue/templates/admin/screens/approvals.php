<?php
/**
 * Approvals: the requests waiting (room sections, segments, nights; listing changes apart), the
 * ones being written (polled), or the history tab.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

if (isset($view['error'])) :
    View::render('admin/screens/parts/card-error', $view);

    return;
endif;

if ($view['history'] !== null) :
    $ritmo_history = $view['history'];
    ?>
    <section class="ritmo-card ritmo-wide" data-ritmo="history">
        <?php if ($ritmo_history['rows'] === []) : ?>
            <p data-ritmo="empty"><?php esc_html_e('No request was decided yet.', 'ritmo-revenue'); ?></p>
        <?php else : ?>
            <table class="widefat striped ritmo-table">
                <caption><?php esc_html_e('Requests, newest first', 'ritmo-revenue'); ?></caption>
                <thead><tr>
                    <th scope="col"><?php esc_html_e('Created', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Kind', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Entries', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Status', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Decided by', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Decided at', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Note', 'ritmo-revenue'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($ritmo_history['rows'] as $ritmo_row) : ?>
                    <tr>
                        <th scope="row" data-label="<?php esc_attr_e('Created', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['created']); ?></th>
                        <td data-label="<?php esc_attr_e('Kind', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['kind']); ?></td>
                        <td data-label="<?php esc_attr_e('Entries', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['nights']); ?></td>
                        <td data-label="<?php esc_attr_e('Status', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['status']); ?></td>
                        <td data-label="<?php esc_attr_e('Decided by', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['by']); ?></td>
                        <td data-label="<?php esc_attr_e('Decided at', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['at']); ?></td>
                        <td data-label="<?php esc_attr_e('Note', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['note']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="ritmo-actions">
                <?php if ($ritmo_history['previous'] !== '') : ?><a href="<?php echo esc_url($ritmo_history['previous']); ?>"><?php esc_html_e('Previous', 'ritmo-revenue'); ?></a><?php endif; ?>
                <?php if ($ritmo_history['next'] !== '') : ?><a href="<?php echo esc_url($ritmo_history['next']); ?>"><?php esc_html_e('Next', 'ritmo-revenue'); ?></a><?php endif; ?>
            </p>
        <?php endif; ?>
    </section>
    <?php
    return;
endif;
?>

<?php foreach ($view['written'] as $ritmo_written) : ?>
    <section class="ritmo-card" data-ritmo="approval-progress"<?php if (!$ritmo_written['final']) : ?> data-ritmo-js="poll"<?php endif; ?> data-rest-url="<?php echo esc_url($view['rest_url'] . 'approvals/' . $ritmo_written['id']); ?>" data-rest-nonce="<?php echo esc_attr($view['rest_nonce']); ?>" data-interval="5" data-max="24" data-final="written,lapsed,expired,rejected,superseded" data-timeout-text="<?php esc_attr_e('Still running: reload the page later.', 'ritmo-revenue'); ?>">
        <h2><?php echo esc_html($ritmo_written['final'] ? __('Decided', 'ritmo-revenue') : __('Approved, being written', 'ritmo-revenue')); ?></h2>
        <p><?php echo esc_html($ritmo_written['title']); ?></p>
        <p><?php echo esc_html($ritmo_written['decided']); ?></p>
        <p role="status" class="ritmo-progress"><?php if (!$ritmo_written['final']) : ?><span class="spinner is-active" aria-hidden="true"></span><?php endif; ?><span data-ritmo-status><?php echo esc_html($ritmo_written['status']); ?></span></p>
    </section>
<?php endforeach; ?>

<?php if ($view['empty']) : ?>
    <div class="ritmo-empty" data-ritmo="empty">
        <h2><?php esc_html_e('Nothing is waiting for approval', 'ritmo-revenue'); ?></h2>
        <p><?php echo esc_html($view['empty_text']); ?></p>
    </div>
<?php endif; ?>

<?php foreach ($view['requests'] as $ritmo_request) : ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ritmo-card ritmo-wide ritmo-request" data-ritmo="approval-request" data-ritmo-js="selection-tree"
          <?php /* translators: 1: nights selected, 2: nights up, 3: nights down (filled in by the browser) */ ?>
          data-text-total="<?php esc_attr_e('Selected: %1$s nights · %2$s up · %3$s down', 'ritmo-revenue'); ?>"
          data-text-linked="<?php esc_attr_e('Linked entries were selected too.', 'ritmo-revenue'); ?>">
        <?php wp_nonce_field('ritmo_approval_decide:' . $ritmo_request['id']); ?>
        <input type="hidden" name="action" value="ritmo_approval_decide">
        <input type="hidden" name="request_id" value="<?php echo esc_attr((string) $ritmo_request['id']); ?>">
        <input type="hidden" name="property_id" value="<?php echo esc_attr($ritmo_request['property_id']); ?>">
        <h2><?php echo esc_html($ritmo_request['title']); ?></h2>
        <p class="<?php echo esc_attr($ritmo_request['expired'] ? 'ritmo-error' : 'ritmo-muted'); ?>"><?php echo esc_html($ritmo_request['expiry']); ?></p>
        <ul class="ritmo-impact" data-ritmo="impact" aria-label="<?php esc_attr_e('Impact', 'ritmo-revenue'); ?>">
            <?php foreach ($ritmo_request['impact'] as $ritmo_line) : ?><li><?php echo esc_html($ritmo_line); ?></li><?php endforeach; ?>
        </ul>
        <?php foreach ($ritmo_request['pairs'] as $ritmo_pair) : ?>
            <p class="ritmo-hint"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> <?php echo esc_html($ritmo_pair); ?></p>
        <?php endforeach; ?>

        <?php if ($ritmo_request['listing'] !== []) : ?>
            <fieldset class="ritmo-block" data-ritmo="listing-section">
                <legend><?php esc_html_e('Changes of the listing itself', 'ritmo-revenue'); ?></legend>
                <?php if (!$ritmo_request['can_listing']) : ?>
                    <p class="ritmo-muted"><?php esc_html_e('Only a person allowed to change safety limits can approve this.', 'ritmo-revenue'); ?></p>
                <?php endif; ?>
                <?php foreach ($ritmo_request['listing'] as $ritmo_value) : ?>
                    <div class="ritmo-listing-row">
                        <?php if ($ritmo_request['can_listing']) : ?>
                            <label><input type="checkbox" name="listing[]" value="<?php echo esc_attr($ritmo_value['key']); ?>"> <?php echo esc_html($ritmo_value['label']); ?></label>
                        <?php else : ?>
                            <span><?php echo esc_html($ritmo_value['label']); ?></span>
                        <?php endif; ?>
                        <?php View::render('admin/screens/parts/price', $ritmo_value); ?>
                        <?php if ($ritmo_request['can_listing']) : ?>
                            <p class="ritmo-confirm" data-ritmo-js="confirm-typed">
                                <label for="ritmo-confirm-<?php echo esc_attr($ritmo_value['field']); ?>"><?php esc_html_e('To confirm, type the new amount:', 'ritmo-revenue'); ?> <code><?php echo esc_html($ritmo_value['type']); ?></code></label>
                                <input type="text" inputmode="decimal" autocomplete="off" id="ritmo-confirm-<?php echo esc_attr($ritmo_value['field']); ?>" name="confirm_<?php echo esc_attr($ritmo_value['field']); ?>" data-expected="<?php echo esc_attr($ritmo_value['type']); ?>">
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>

        <?php if ($ritmo_request['pms'] !== []) : ?>
            <fieldset class="ritmo-block" data-ritmo="pms-section">
                <legend><?php esc_html_e('Stay and arrival/departure in the PMS', 'ritmo-revenue'); ?></legend>
                <p class="ritmo-muted"><?php esc_html_e('Written to the PMS after the prices; never a price, never a booking.', 'ritmo-revenue'); ?></p>
                <?php foreach ($ritmo_request['pms'] as $ritmo_change) : ?>
                    <div class="ritmo-listing-row">
                        <?php if ($ritmo_request['can_approve']) : ?>
                            <label><input type="checkbox" name="entries[]" value="<?php echo esc_attr($ritmo_change['key']); ?>" data-ritmo="pms-check" checked> <?php echo esc_html($ritmo_change['label']); ?></label>
                        <?php else : ?>
                            <span><?php echo esc_html($ritmo_change['label']); ?></span>
                        <?php endif; ?>
                        <ul class="ritmo-plain">
                            <?php foreach ($ritmo_change['lines'] as $ritmo_line) : ?>
                                <li><?php echo esc_html($ritmo_line); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>

        <?php foreach ($ritmo_request['rooms'] as $ritmo_room) : ?>
            <details class="ritmo-room" data-ritmo="room-section" <?php if ($ritmo_room['open']) : ?>open<?php endif; ?>>
                <summary>
                    <?php if ($ritmo_request['can_approve']) : ?><input type="checkbox" class="ritmo-js-only" hidden data-level="room" checked aria-label="<?php echo esc_attr($ritmo_room['name']); ?>"><?php endif; ?>
                    <strong><?php echo esc_html($ritmo_room['name']); ?></strong> — <?php echo esc_html($ritmo_room['count']); ?>
                </summary>
                <?php foreach ($ritmo_room['segments'] as $ritmo_segment) : ?>
                    <details class="ritmo-segment ritmo-dir-<?php echo esc_attr($ritmo_segment['direction']); ?>" data-ritmo="segment">
                        <summary>
                            <?php if ($ritmo_request['can_approve']) : ?><input type="checkbox" class="ritmo-js-only" hidden data-level="segment" data-ritmo="segment-check" checked aria-label="<?php echo esc_attr($ritmo_room['name'] . ' ' . $ritmo_segment['range']); ?>"><?php endif; ?>
                            <span class="ritmo-seg-range"><?php echo esc_html($ritmo_segment['range']); ?></span>
                            · <?php echo esc_html($ritmo_segment['nights']); ?>
                            · <?php echo esc_html($ritmo_segment['reason']); ?>
                            · <span class="ritmo-price" data-ritmo="price"><?php echo esc_html($ritmo_segment['change']); ?></span>
                            <?php if ($ritmo_segment['median'] !== '') : ?><span class="ritmo-pct">(<?php echo esc_html($ritmo_segment['median']); ?>)</span><?php endif; ?>
                            <?php foreach ($ritmo_segment['warnings'] as $ritmo_w) : ?>
                                <span class="ritmo-badge ritmo-tone-attention" data-ritmo="warning-<?php echo esc_attr($ritmo_w['code']); ?>"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php echo esc_html($ritmo_w['text']); ?></span>
                            <?php endforeach; ?>
                        </summary>
                        <ul class="ritmo-nights">
                            <?php foreach ($ritmo_segment['rows'] as $ritmo_night) : ?>
                                <li class="ritmo-night" data-ritmo="night-row" data-kind="<?php echo esc_attr($ritmo_night['kind']); ?>" data-group="<?php echo esc_attr($ritmo_night['group']); ?>" data-direction="<?php echo esc_attr($ritmo_night['direction']); ?>" data-before="<?php echo esc_attr(sprintf('%.2F', $ritmo_night['before'])); ?>" data-after="<?php echo esc_attr(sprintf('%.2F', $ritmo_night['after'])); ?>">
                                    <?php if ($ritmo_request['can_approve'] && !$ritmo_night['held']) : ?>
                                        <input type="checkbox" name="entries[]" value="<?php echo esc_attr($ritmo_night['key']); ?>" id="ritmo-e-<?php echo esc_attr(md5($ritmo_night['key'])); ?>" data-ritmo="night-check" checked>
                                    <?php endif; ?>
                                    <label for="ritmo-e-<?php echo esc_attr(md5($ritmo_night['key'])); ?>"><strong><?php echo esc_html($ritmo_night['date']); ?></strong></label>
                                    <?php foreach ($ritmo_night['changes'] as $ritmo_change) : ?>
                                        <span class="ritmo-change"><?php echo esc_html($ritmo_change['field']); ?><?php if ($ritmo_change['price'] !== null) : ?> <?php View::render('admin/screens/parts/price', $ritmo_change); ?><?php endif; ?></span>
                                    <?php endforeach; ?>
                                    <?php if ($ritmo_night['pace'] !== '') : ?><span class="ritmo-muted"><?php echo esc_html($ritmo_night['pace']); ?></span><?php endif; ?>
                                    <?php if ($ritmo_night['reason'] !== '') : ?><span class="ritmo-reason" data-ritmo="reason"><?php echo esc_html($ritmo_night['reason']); ?></span><?php endif; ?>
                                    <?php foreach ($ritmo_night['warnings'] as $ritmo_w) : ?>
                                        <span class="ritmo-warning" data-ritmo="warning-<?php echo esc_attr($ritmo_w['code']); ?>"><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php echo esc_html($ritmo_w['text']); ?></span>
                                    <?php endforeach; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endforeach; ?>
            </details>
        <?php endforeach; ?>

        <?php if ($ritmo_request['can_approve']) : ?>
            <p role="status" class="ritmo-total" data-ritmo="selection-total"></p>
            <p>
                <label for="ritmo-note-<?php echo esc_attr((string) $ritmo_request['id']); ?>"><?php esc_html_e('Note (optional)', 'ritmo-revenue'); ?></label>
                <input type="text" class="regular-text" maxlength="500" id="ritmo-note-<?php echo esc_attr((string) $ritmo_request['id']); ?>" name="note">
            </p>
            <p class="ritmo-buttons">
                <button type="submit" name="decision" value="selected" class="button button-primary" data-ritmo="approve-selected"><?php esc_html_e('Approve selected', 'ritmo-revenue'); ?></button>
                <button type="submit" name="decision" value="all" class="button" data-ritmo="approve-all"><?php esc_html_e('Approve everything', 'ritmo-revenue'); ?></button>
                <button type="submit" name="decision" value="reject" class="button button-link-delete" data-ritmo="refuse"><?php esc_html_e('Refuse the plan', 'ritmo-revenue'); ?></button>
            </p>
        <?php else : ?>
            <p class="ritmo-muted"><?php esc_html_e('Only a person allowed to approve can decide this plan.', 'ritmo-revenue'); ?></p>
        <?php endif; ?>
    </form>
<?php endforeach; ?>
