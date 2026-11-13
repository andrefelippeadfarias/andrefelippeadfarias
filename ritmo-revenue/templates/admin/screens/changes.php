<?php
/**
 * Change log: filters, the changes grouped by run, the undo of ticked changes and the export.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

if ($view['error'] !== null) :
    View::render('admin/screens/parts/card-error', $view);

    return;
endif;
if (!isset($view['filter'])) : ?>
    <p data-ritmo="empty"><?php esc_html_e('There is no property yet.', 'ritmo-revenue'); ?></p>
    <?php
    return;
endif;
$ritmo_f = $view['filter'];
?>
<details class="ritmo-filters">
    <summary><?php esc_html_e('Filters', 'ritmo-revenue'); ?></summary>
    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
        <input type="hidden" name="page" value="ritmo-revenue-changes">
        <input type="hidden" name="property" value="<?php echo esc_attr($view['property_id']); ?>">
        <p><label for="ritmo-c-room"><?php esc_html_e('Room', 'ritmo-revenue'); ?></label>
            <select id="ritmo-c-room" name="room"><option value=""><?php esc_html_e('every room', 'ritmo-revenue'); ?></option>
                <?php foreach ($ritmo_f['room_options'] as $ritmo_room) : ?><option value="<?php echo esc_attr($ritmo_room['code']); ?>" <?php selected($ritmo_room['code'], $ritmo_f['room'] ?? ''); ?>><?php echo esc_html($ritmo_room['name']); ?></option><?php endforeach; ?>
            </select></p>
        <p><label for="ritmo-c-nf"><?php esc_html_e('Nights from', 'ritmo-revenue'); ?></label> <input type="date" id="ritmo-c-nf" name="night_from" value="<?php echo esc_attr($ritmo_f['night_from'] ?? ''); ?>">
            <label for="ritmo-c-nt"><?php esc_html_e('to', 'ritmo-revenue'); ?></label> <input type="date" id="ritmo-c-nt" name="night_to" value="<?php echo esc_attr($ritmo_f['night_to'] ?? ''); ?>"></p>
        <p><label for="ritmo-c-cf"><?php esc_html_e('Changed from', 'ritmo-revenue'); ?></label> <input type="date" id="ritmo-c-cf" name="changed_from" value="<?php echo esc_attr($ritmo_f['changed_from'] ?? ''); ?>">
            <label for="ritmo-c-ct"><?php esc_html_e('to', 'ritmo-revenue'); ?></label> <input type="date" id="ritmo-c-ct" name="changed_to" value="<?php echo esc_attr($ritmo_f['changed_to'] ?? ''); ?>"></p>
        <p><label for="ritmo-c-sys"><?php esc_html_e('System', 'ritmo-revenue'); ?></label>
            <select id="ritmo-c-sys" name="system">
                <option value=""><?php esc_html_e('every system', 'ritmo-revenue'); ?></option>
                <option value="pricing" <?php selected('pricing', $ritmo_f['system'] ?? ''); ?>><?php esc_html_e('pricing system', 'ritmo-revenue'); ?></option>
                <option value="pms" <?php selected('pms', $ritmo_f['system'] ?? ''); ?>><?php esc_html_e('PMS', 'ritmo-revenue'); ?></option>
                <option value="listing" <?php selected('listing', $ritmo_f['system'] ?? ''); ?>><?php esc_html_e('listing', 'ritmo-revenue'); ?></option>
            </select>
            <label for="ritmo-c-who"><?php esc_html_e('Who', 'ritmo-revenue'); ?></label>
            <select id="ritmo-c-who" name="who">
                <option value=""><?php esc_html_e('anyone', 'ritmo-revenue'); ?></option>
                <option value="autonomous" <?php selected('autonomous', $ritmo_f['who'] ?? ''); ?>><?php esc_html_e('autonomous runs', 'ritmo-revenue'); ?></option>
                <option value="person" <?php selected('person', $ritmo_f['who'] ?? ''); ?>><?php esc_html_e('approved by a person', 'ritmo-revenue'); ?></option>
                <option value="undo" <?php selected('undo', $ritmo_f['who'] ?? ''); ?>><?php esc_html_e('undo', 'ritmo-revenue'); ?></option>
            </select></p>
        <p><label><input type="checkbox" name="not_undone" value="1" <?php checked(isset($ritmo_f['not_undone'])); ?>> <?php esc_html_e('only changes not undone', 'ritmo-revenue'); ?></label></p>
        <p class="ritmo-actions"><button type="submit" class="button"><?php esc_html_e('Apply', 'ritmo-revenue'); ?></button> <a href="<?php echo esc_url($view['clear']); ?>"><?php esc_html_e('Clear', 'ritmo-revenue'); ?></a></p>
    </form>
</details>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ritmo-inline">
    <?php wp_nonce_field('ritmo_changes_export'); ?>
    <input type="hidden" name="action" value="ritmo_changes_export">
    <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
    <?php foreach ($ritmo_f as $ritmo_name => $ritmo_value) : if (!is_string($ritmo_value)) { continue; } ?>
        <input type="hidden" name="<?php echo esc_attr($ritmo_name); ?>" value="<?php echo esc_attr($ritmo_value); ?>">
    <?php endforeach; ?>
    <button type="submit" class="button" data-ritmo="changes-export"><?php esc_html_e('Export CSV', 'ritmo-revenue'); ?></button>
</form>

<?php if ($view['empty']) : ?>
    <div class="ritmo-empty" data-ritmo="empty">
        <p><?php esc_html_e('Nothing was written yet. In shadow mode the plugin writes nothing; see what it would do in Nights.', 'ritmo-revenue'); ?></p>
    </div>
    <?php
    return;
endif;
?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('ritmo_undo_request'); ?>
    <input type="hidden" name="action" value="ritmo_undo_request">
    <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
    <?php foreach ($view['groups'] as $ritmo_group) : ?>
        <section class="ritmo-card ritmo-wide" data-ritmo="run-group">
            <h2><?php echo esc_html($ritmo_group['title']); ?></h2>
            <table class="widefat striped ritmo-table">
                <caption class="screen-reader-text"><?php echo esc_html($ritmo_group['title']); ?></caption>
                <thead><tr>
                    <?php if ($view['can_undo']) : ?><th scope="col"><span class="screen-reader-text"><?php esc_html_e('Undo', 'ritmo-revenue'); ?></span></th><?php endif; ?>
                    <th scope="col"><?php esc_html_e('Room and night', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('What changed', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Before → after', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Why', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Confirmed', 'ritmo-revenue'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($ritmo_group['rows'] as $ritmo_row) : ?>
                    <tr data-ritmo="change">
                        <?php if ($view['can_undo']) : ?>
                            <td data-label="<?php esc_attr_e('Undo', 'ritmo-revenue'); ?>"><?php if ($ritmo_row['undoable']) : ?><input type="checkbox" name="changes[]" value="<?php echo esc_attr((string) $ritmo_row['id']); ?>" aria-label="<?php echo esc_attr($ritmo_row['room'] . ' ' . $ritmo_row['night'] . ' ' . $ritmo_row['what']); ?>"><?php endif; ?></td>
                        <?php endif; ?>
                        <th scope="row" data-label="<?php esc_attr_e('Room and night', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['room']); ?> · <?php echo esc_html($ritmo_row['night']); ?></th>
                        <td data-label="<?php esc_attr_e('What changed', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['what']); ?> <span class="ritmo-muted">(<?php echo esc_html($ritmo_row['system']); ?>)</span></td>
                        <td data-label="<?php esc_attr_e('Before → after', 'ritmo-revenue'); ?>"><?php View::render('admin/screens/parts/price', ['price' => $ritmo_row['price']]); ?></td>
                        <td data-label="<?php esc_attr_e('Why', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['reason']); ?></td>
                        <td data-label="<?php esc_attr_e('Confirmed', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['confirm']); ?><?php if ($ritmo_row['undone'] !== '') : ?> · <strong><?php echo esc_html($ritmo_row['undone']); ?></strong><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endforeach; ?>
    <p class="ritmo-actions">
        <span><?php echo esc_html($view['paging']['label']); ?></span>
        <?php if ($view['paging']['previous'] !== '') : ?><a href="<?php echo esc_url($view['paging']['previous']); ?>"><?php esc_html_e('Previous', 'ritmo-revenue'); ?></a><?php endif; ?>
        <?php if ($view['paging']['next'] !== '') : ?><a href="<?php echo esc_url($view['paging']['next']); ?>"><?php esc_html_e('Next', 'ritmo-revenue'); ?></a><?php endif; ?>
    </p>
    <?php if ($view['can_undo']) : ?>
        <p><button type="submit" class="button" data-ritmo="undo-prepare"><?php esc_html_e('Prepare the undo of the ticked changes', 'ritmo-revenue'); ?></button></p>
    <?php endif; ?>
</form>
