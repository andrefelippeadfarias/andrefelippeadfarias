<?php
/**
 * The Orders screen: orders in force, frozen nights, ended orders, and the form of a new order.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
$ritmo_property = $ritmo_page->property?->id ?? '';
?>
<?php if ($view['no_property']) : ?>
    <div class="ritmo-empty" data-ritmo="empty"><p><?php esc_html_e('Create a property first: orders are given per property.', 'ritmo-revenue'); ?></p></div>
<?php elseif ($ritmo_page->tab === 'new') : ?>
    <?php $ritmo_form = $view['form']; ?>
    <?php if ($ritmo_form['type'] === '') : ?>
        <form method="get" class="ritmo-card">
            <input type="hidden" name="page" value="<?php echo esc_attr($ritmo_page->slug); ?>">
            <input type="hidden" name="tab" value="new">
            <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
            <fieldset>
                <legend><?php esc_html_e('What do you want for some nights?', 'ritmo-revenue'); ?></legend>
                <?php foreach ($ritmo_form['types'] as $ritmo_i => $ritmo_type) : ?>
                    <label class="ritmo-choice" data-ritmo="order-type-<?php echo esc_attr($ritmo_type['value']); ?>"><input type="radio" name="type" value="<?php echo esc_attr($ritmo_type['value']); ?>" <?php checked($ritmo_i === 0); ?>> <?php echo esc_html($ritmo_type['label']); ?></label>
                <?php endforeach; ?>
            </fieldset>
            <button type="submit" class="button button-primary"><?php esc_html_e('Continue', 'ritmo-revenue'); ?></button>
        </form>
    <?php else : ?>
        <?php $ritmo_copy = $ritmo_form['copy']; ?>
        <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card ritmo-form" data-ritmo="order-form">
            <?php wp_nonce_field('ritmo_order_create'); ?>
            <input type="hidden" name="action" value="ritmo_order_create">
            <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
            <input type="hidden" name="tab" value="new">
            <input type="hidden" name="order[type]" value="<?php echo esc_attr($ritmo_form['type']); ?>">
            <?php if ($ritmo_copy !== null) : ?><input type="hidden" name="copy" value="<?php echo esc_attr((string) $ritmo_copy['id']); ?>"><?php endif; ?>
            <h2><?php foreach ($ritmo_form['types'] as $ritmo_type) : ?><?php if ($ritmo_type['value'] === $ritmo_form['type']) : ?><?php echo esc_html($ritmo_type['label']); ?><?php endif; ?><?php endforeach; ?></h2>
            <fieldset id="ritmo-f-order-rooms">
                <legend><?php esc_html_e('Rooms', 'ritmo-revenue'); ?></legend>
                <label class="ritmo-check"><input type="checkbox" name="order[rooms][]" value="*" <?php checked(in_array('*', $ritmo_copy['rooms'] ?? [], true)); ?>> <?php esc_html_e('All rooms', 'ritmo-revenue'); ?></label>
                <?php foreach ($ritmo_form['rooms'] as $ritmo_code => $ritmo_name) : ?>
                    <label class="ritmo-check"><input type="checkbox" name="order[rooms][]" value="<?php echo esc_attr((string) $ritmo_code); ?>" <?php checked(in_array($ritmo_code, $ritmo_copy['rooms'] ?? [], true)); ?>> <?php echo esc_html($ritmo_name); ?></label>
                <?php endforeach; ?>
            </fieldset>
            <p><label for="ritmo-f-order-from"><?php esc_html_e('First night', 'ritmo-revenue'); ?></label><br><input type="date" id="ritmo-f-order-from" name="order[from]" value="<?php echo esc_attr($ritmo_copy['from'] ?? ''); ?>"></p>
            <p><label for="ritmo-f-order-to"><?php esc_html_e('Last night', 'ritmo-revenue'); ?></label><br><input type="date" id="ritmo-f-order-to" name="order[to]" value="<?php echo esc_attr($ritmo_copy['to'] ?? ''); ?>"></p>
            <p><label for="ritmo-f-order-nights"><?php esc_html_e('Or only these nights (one date per line, YYYY-MM-DD)', 'ritmo-revenue'); ?></label><br><textarea id="ritmo-f-order-nights" name="order[nights]" rows="3"><?php echo esc_html(implode("\n", $ritmo_copy['nights'] ?? [])); ?></textarea></p>
            <?php if (in_array($ritmo_form['type'], ['floor', 'target'], true)) : ?>
                <fieldset>
                    <legend><?php echo esc_html($ritmo_form['type'] === 'floor' ? __('Minimum price per room (price sent to the channels)', 'ritmo-revenue') : __('Minimum price on weak nights (optional)', 'ritmo-revenue')); ?></legend>
                    <?php foreach ($ritmo_form['rooms'] as $ritmo_code => $ritmo_name) : ?>
                        <p><label for="ritmo-f-order-floors-<?php echo esc_attr((string) $ritmo_code); ?>"><?php echo esc_html($ritmo_name); ?></label><br>
                            <input type="number" inputmode="decimal" min="0" step="0.01" id="ritmo-f-order-floors-<?php echo esc_attr((string) $ritmo_code); ?>" name="order[floors][<?php echo esc_attr((string) $ritmo_code); ?>]" value="<?php echo esc_attr(isset($ritmo_copy['floors'][$ritmo_code]) ? (string) $ritmo_copy['floors'][$ritmo_code] : ''); ?>"> <?php echo esc_html($ritmo_page->currency()); ?></p>
                    <?php endforeach; ?>
                    <?php if ($ritmo_form['type'] === 'floor') : ?><p class="ritmo-help"><?php esc_html_e('Never below the absolute floor of the room, whatever you type.', 'ritmo-revenue'); ?></p><?php endif; ?>
                </fieldset>
            <?php endif; ?>
            <?php if ($ritmo_form['type'] === 'target') : ?>
                <p><label for="ritmo-f-order-percentile"><?php esc_html_e('Market percentile (0 is the cheapest competitor, 100 the dearest)', 'ritmo-revenue'); ?></label><br><input type="number" id="ritmo-f-order-percentile" name="order[percentile]" min="0" max="110" step="1" value="<?php echo esc_attr(isset($ritmo_copy['percentile']) ? (string) (int) $ritmo_copy['percentile'] : $ritmo_form['percentile']); ?>"></p>
            <?php endif; ?>
            <?php if (in_array($ritmo_form['type'], ['floor', 'rule', 'restriction'], true)) : ?>
                <fieldset id="ritmo-f-order-releases">
                    <legend><?php esc_html_e('What it allows', 'ritmo-revenue'); ?></legend>
                    <?php foreach ($ritmo_form['releases'] as $ritmo_release) : ?>
                        <?php
                        $ritmo_allowed = ['floor' => ['below_standard_floor'], 'restriction' => ['stay', 'orphan_stay', 'arrival_departure']][$ritmo_form['type']] ?? null;
                        if ($ritmo_allowed !== null && !in_array($ritmo_release['value'], $ritmo_allowed, true)) {
                            continue;
                        }
                        ?>
                        <label class="ritmo-check"><input type="checkbox" name="order[releases][]" value="<?php echo esc_attr($ritmo_release['value']); ?>" <?php checked(in_array($ritmo_release['value'], $ritmo_copy['releases'] ?? [], true)); ?> <?php disabled($ritmo_release['safety'] && !$ritmo_form['may_safety']); ?>> <?php echo esc_html($ritmo_release['text']); ?><?php if ($ritmo_release['safety']) : ?> <span class="ritmo-badge ritmo-tone-attention"><?php esc_html_e('Safety limit', 'ritmo-revenue'); ?></span><?php endif; ?></label>
                    <?php endforeach; ?>
                    <?php if ($ritmo_form['may_safety']) : ?>
                        <p><label for="ritmo-order-confirmation"><?php esc_html_e('To allow a safety limit, type the property id:', 'ritmo-revenue'); ?> <code><?php echo esc_html($ritmo_form['property_id']); ?></code></label><br>
                            <input type="text" id="ritmo-order-confirmation" name="confirmation" autocomplete="off" data-expected="<?php echo esc_attr($ritmo_form['property_id']); ?>"></p>
                    <?php else : ?>
                        <p class="ritmo-help"><?php esc_html_e('The items marked as safety limits need a person allowed to change safety limits.', 'ritmo-revenue'); ?></p>
                    <?php endif; ?>
                </fieldset>
            <?php endif; ?>
            <p><label for="ritmo-f-order-note"><?php esc_html_e('Note (optional; no guest data)', 'ritmo-revenue'); ?></label><br><textarea id="ritmo-f-order-note" name="order[note]" rows="2" maxlength="500"><?php echo esc_html($ritmo_copy['note'] ?? ''); ?></textarea></p>
            <?php if ($ritmo_copy !== null) : ?>
                <p><label class="ritmo-check"><input type="checkbox" name="end_original" value="1" checked> <?php esc_html_e('End the original order', 'ritmo-revenue'); ?></label></p>
            <?php endif; ?>
            <p class="ritmo-help"><?php esc_html_e('An order applies from the next run. It is never edited: to change it, copy it and end the original.', 'ritmo-revenue'); ?></p>
            <p><button type="submit" class="button button-primary"><?php esc_html_e('Save the order', 'ritmo-revenue'); ?></button></p>
        </form>
    <?php endif; ?>
<?php else : ?>
    <?php if ($ritmo_page->tab === 'freezes') : ?>
        <section class="ritmo-card">
            <h2><?php esc_html_e('Nights changed by someone else', 'ritmo-revenue'); ?></h2>
            <?php if ($view['detected'] === []) : ?>
                <p data-ritmo="empty"><?php esc_html_e('No night is frozen.', 'ritmo-revenue'); ?></p>
            <?php else : ?>
                <table class="widefat striped ritmo-table">
                    <caption><?php esc_html_e('The plugin leaves these nights alone until they are released or the freeze ends', 'ritmo-revenue'); ?></caption>
                    <thead><tr><th scope="col"><?php esc_html_e('Room', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Nights', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('What changed', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Frozen', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Release', 'ritmo-revenue'); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($view['detected'] as $ritmo_freeze) : ?>
                            <tr data-ritmo="freeze-row">
                                <th scope="row" data-label="<?php esc_attr_e('Room', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_freeze['room']); ?></th>
                                <td data-label="<?php esc_attr_e('Nights', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_freeze['nights']); ?></td>
                                <td data-label="<?php esc_attr_e('What changed', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_freeze['fields']); ?></td>
                                <td data-label="<?php esc_attr_e('Frozen', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_freeze['until']); ?></td>
                                <td data-label="<?php esc_attr_e('Release', 'ritmo-revenue'); ?>">
                                    <?php if ($view['may_approve']) : ?>
                                        <form method="post" action="<?php echo esc_url($view['post_url']); ?>">
                                            <?php wp_nonce_field('ritmo_order_freeze_release:' . $ritmo_freeze['id']); ?>
                                            <input type="hidden" name="action" value="ritmo_order_freeze_release">
                                            <input type="hidden" name="freeze" value="<?php echo esc_attr((string) $ritmo_freeze['id']); ?>">
                                            <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
                                            <input type="hidden" name="tab" value="freezes">
                                            <label class="ritmo-check"><input type="checkbox" name="understood" value="1"> <?php esc_html_e('I understand the plugin may change these nights again from the next run', 'ritmo-revenue'); ?></label>
                                            <button type="submit" class="button" data-ritmo="freeze-release"><?php esc_html_e('Release', 'ritmo-revenue'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($view['rows'] === []) : ?>
        <?php if ($ritmo_page->tab === 'active') : ?>
            <div class="ritmo-empty" data-ritmo="empty">
                <p><?php esc_html_e('No order: the plugin follows the rules alone.', 'ritmo-revenue'); ?></p>
                <?php if ($view['may_rules']) : ?><p><a class="button button-primary" href="<?php echo esc_url($ritmo_page->url(['tab' => 'new'])); ?>"><?php esc_html_e('Give an order', 'ritmo-revenue'); ?></a></p><?php endif; ?>
            </div>
        <?php elseif ($ritmo_page->tab === 'ended') : ?>
            <p data-ritmo="empty"><?php esc_html_e('No order ended in the last 180 days.', 'ritmo-revenue'); ?></p>
        <?php endif; ?>
    <?php else : ?>
        <table class="widefat striped ritmo-table">
            <caption><?php echo esc_html($ritmo_page->tab === 'ended' ? __('Orders ended or past, last 180 days', 'ritmo-revenue') : ($ritmo_page->tab === 'freezes' ? __('Nights the plugin froze after a change by hand', 'ritmo-revenue') : __('Orders in force today or later', 'ritmo-revenue'))); ?></caption>
            <thead><tr>
                <th scope="col"><?php esc_html_e('Order', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Rooms', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Nights', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('What it does', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Given by', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Actions', 'ritmo-revenue'); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($view['rows'] as $ritmo_row) : ?>
                    <tr data-ritmo="order-row">
                        <th scope="row" data-label="<?php esc_attr_e('Order', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['name']); ?><?php if ($ritmo_row['type'] !== '') : ?><br><span class="ritmo-muted"><?php echo esc_html($ritmo_row['type']); ?></span><?php endif; ?></th>
                        <td data-label="<?php esc_attr_e('Rooms', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['rooms']); ?></td>
                        <td data-label="<?php esc_attr_e('Nights', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['nights']); ?></td>
                        <td data-label="<?php esc_attr_e('What it does', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['does']); ?>
                            <?php if ($ritmo_row['releases'] !== []) : ?><ul class="ritmo-releases"><?php foreach ($ritmo_row['releases'] as $ritmo_release) : ?><li><?php echo esc_html($ritmo_release); ?></li><?php endforeach; ?></ul><?php endif; ?>
                        </td>
                        <td data-label="<?php esc_attr_e('Given by', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['by'] . ' · ' . $ritmo_row['at']); ?><?php if ($ritmo_row['ended'] !== '') : ?><br><?php echo esc_html(sprintf(/* translators: %s: date and time */ __('Ended %s', 'ritmo-revenue'), $ritmo_row['ended'])); ?><?php endif; ?></td>
                        <td data-label="<?php esc_attr_e('Actions', 'ritmo-revenue'); ?>">
                            <?php if ($view['may_rules'] && $ritmo_page->tab === 'active') : ?>
                                <?php if ($ritmo_row['readable']) : ?><a class="button" data-ritmo="order-copy" href="<?php echo esc_url($ritmo_page->url(['tab' => 'new', 'copy' => $ritmo_row['id']])); ?>"><?php esc_html_e('Copy', 'ritmo-revenue'); ?></a><?php endif; ?>
                                <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-inline">
                                    <?php wp_nonce_field('ritmo_order_end:' . $ritmo_row['id']); ?>
                                    <input type="hidden" name="action" value="ritmo_order_end">
                                    <input type="hidden" name="order" value="<?php echo esc_attr((string) $ritmo_row['id']); ?>">
                                    <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
                                    <input type="hidden" name="tab" value="active">
                                    <button type="submit" class="button" data-ritmo="order-end"><?php esc_html_e('End it', 'ritmo-revenue'); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>
