<?php
/**
 * One field of a rules form (spec 05, 5.5.3), drawn from a node of FieldView: label, control,
 * unit, help, allowed range, default, the "Changed" mark and the error; groups, maps and list
 * items draw their children with this same template.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_n = $view['node'];
$ritmo_described = $ritmo_n['id'] . '-help';
$ritmo_error_id = $ritmo_n['id'] . '-error';
$ritmo_aria = trim($ritmo_described . ' ' . ($ritmo_n['error'] !== null ? $ritmo_error_id : ''));
?>
<?php if ($ritmo_n['kind'] === 'group' || $ritmo_n['kind'] === 'map') : ?>
    <fieldset class="ritmo-group" data-ritmo="<?php echo esc_attr($ritmo_n['hook']); ?>">
        <legend><?php echo esc_html($ritmo_n['label']); ?></legend>
        <?php if ($ritmo_n['help'] !== '') : ?><p class="ritmo-help"><?php echo esc_html($ritmo_n['help']); ?></p><?php endif; ?>
        <?php $ritmo_advanced = array_values(array_filter($ritmo_n['children'], static fn (array $c): bool => (bool) ($c['advanced'] ?? false))); ?>
        <?php foreach ($ritmo_n['children'] as $ritmo_child) : ?>
            <?php if (!($ritmo_child['advanced'] ?? false)) : ?>
                <?php View::render('admin/editors/field', ['node' => $ritmo_child]); ?>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($ritmo_advanced !== []) : ?>
            <details class="ritmo-advanced">
                <summary><?php esc_html_e('Advanced settings', 'ritmo-revenue'); ?></summary>
                <?php foreach ($ritmo_advanced as $ritmo_child) : ?>
                    <?php View::render('admin/editors/field', ['node' => $ritmo_child]); ?>
                <?php endforeach; ?>
            </details>
        <?php endif; ?>
    </fieldset>
<?php elseif ($ritmo_n['kind'] === 'items') : ?>
    <fieldset class="ritmo-group ritmo-items" data-ritmo="<?php echo esc_attr($ritmo_n['hook']); ?>" id="<?php echo esc_attr($ritmo_n['id']); ?>">
        <legend><?php echo esc_html($ritmo_n['label']); ?><?php if ($ritmo_n['changed']) : ?> <span class="ritmo-changed"><?php esc_html_e('Changed', 'ritmo-revenue'); ?></span><?php endif; ?></legend>
        <?php if ($ritmo_n['help'] !== '') : ?><p class="ritmo-help" id="<?php echo esc_attr($ritmo_described); ?>"><?php echo esc_html($ritmo_n['help']); ?></p><?php endif; ?>
        <?php if ($ritmo_n['error'] !== null) : ?><p class="ritmo-field-error" id="<?php echo esc_attr($ritmo_error_id); ?>"><?php echo esc_html($ritmo_n['error']); ?></p><?php endif; ?>
        <?php if ($ritmo_n['items'] === []) : ?><p class="ritmo-muted"><?php esc_html_e('None yet.', 'ritmo-revenue'); ?></p><?php endif; ?>
        <?php foreach ($ritmo_n['items'] as $ritmo_index => $ritmo_item) : ?>
            <fieldset class="ritmo-item">
                <legend><?php echo esc_html($ritmo_item['legend']); ?></legend>
                <?php if ($ritmo_item['node'] !== null) : ?>
                    <?php foreach ($ritmo_item['node']['children'] ?? [$ritmo_item['node']] as $ritmo_child) : ?>
                        <?php View::render('admin/editors/field', ['node' => $ritmo_child]); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!$ritmo_n['disabled']) : ?>
                    <input type="hidden" name="<?php echo esc_attr($ritmo_item['remove_name']); ?>" value="0">
                    <label class="ritmo-remove"><input type="checkbox" name="<?php echo esc_attr($ritmo_item['remove_name']); ?>" value="1"> <?php esc_html_e('Remove this item', 'ritmo-revenue'); ?></label>
                <?php endif; ?>
            </fieldset>
        <?php endforeach; ?>
        <?php if (!$ritmo_n['disabled'] && $ritmo_n['new'] !== null && ($ritmo_n['max'] === null || count($ritmo_n['items']) < $ritmo_n['max'])) : ?>
            <details class="ritmo-item ritmo-new-item">
                <summary><?php esc_html_e('Add an item', 'ritmo-revenue'); ?></summary>
                <input type="hidden" name="<?php echo esc_attr($ritmo_n['add_name']); ?>" value="0">
                <label class="ritmo-add"><input type="checkbox" name="<?php echo esc_attr($ritmo_n['add_name']); ?>" value="1"> <?php esc_html_e('Add this item when I save the section', 'ritmo-revenue'); ?></label>
                <?php foreach ($ritmo_n['new']['children'] ?? [$ritmo_n['new']] as $ritmo_child) : ?>
                    <?php View::render('admin/editors/field', ['node' => $ritmo_child]); ?>
                <?php endforeach; ?>
            </details>
        <?php endif; ?>
    </fieldset>
<?php else : ?>
    <div class="ritmo-field<?php echo esc_attr($ritmo_n['error'] !== null ? ' ritmo-field-invalid' : ''); ?>" data-ritmo="<?php echo esc_attr($ritmo_n['hook']); ?>">
        <?php if ($ritmo_n['kind'] === 'weekdays' || $ritmo_n['kind'] === 'choices') : ?>
            <fieldset id="<?php echo esc_attr($ritmo_n['id']); ?>" aria-describedby="<?php echo esc_attr($ritmo_aria); ?>">
                <legend><?php echo esc_html($ritmo_n['label']); ?><?php if ($ritmo_n['changed']) : ?> <span class="ritmo-changed"><?php esc_html_e('Changed', 'ritmo-revenue'); ?></span><?php endif; ?></legend>
                <input type="hidden" name="<?php echo esc_attr($ritmo_n['name'] . '[__present]'); ?>" value="1">
                <?php foreach ($ritmo_n['options'] as $ritmo_i => $ritmo_option) : ?>
                    <label class="ritmo-check"><input type="checkbox" name="<?php echo esc_attr($ritmo_n['name'] . '[]'); ?>" value="<?php echo esc_attr($ritmo_option['value']); ?>" <?php checked($ritmo_option['checked']); ?> <?php disabled($ritmo_n['disabled']); ?>> <?php echo esc_html($ritmo_option['label']); ?></label>
                <?php endforeach; ?>
            </fieldset>
        <?php elseif ($ritmo_n['kind'] === 'checkbox') : ?>
            <input type="hidden" name="<?php echo esc_attr($ritmo_n['name']); ?>" value="0">
            <label class="ritmo-check" for="<?php echo esc_attr($ritmo_n['id']); ?>"><input type="checkbox" id="<?php echo esc_attr($ritmo_n['id']); ?>" name="<?php echo esc_attr($ritmo_n['name']); ?>" value="1" aria-describedby="<?php echo esc_attr($ritmo_aria); ?>" <?php checked($ritmo_n['checked']); ?> <?php disabled($ritmo_n['disabled']); ?>> <?php echo esc_html($ritmo_n['label']); ?></label>
            <?php if ($ritmo_n['locked']) : ?><span class="ritmo-locked"><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e('Fixed by the product', 'ritmo-revenue'); ?></span><?php endif; ?>
            <?php if ($ritmo_n['changed']) : ?> <span class="ritmo-changed"><?php esc_html_e('Changed', 'ritmo-revenue'); ?></span><?php endif; ?>
        <?php else : ?>
            <label for="<?php echo esc_attr($ritmo_n['id']); ?>"><?php echo esc_html($ritmo_n['label']); ?><?php if ($ritmo_n['changed']) : ?> <span class="ritmo-changed"><?php esc_html_e('Changed', 'ritmo-revenue'); ?></span><?php endif; ?></label>
            <span class="ritmo-control">
                <?php if ($ritmo_n['kind'] === 'select') : ?>
                    <select id="<?php echo esc_attr($ritmo_n['id']); ?>" name="<?php echo esc_attr($ritmo_n['name']); ?>" aria-describedby="<?php echo esc_attr($ritmo_aria); ?>" <?php disabled($ritmo_n['disabled']); ?> <?php if ($ritmo_n['error'] !== null) : ?>aria-invalid="true"<?php endif; ?>>
                        <?php foreach ($ritmo_n['options'] as $ritmo_option) : ?>
                            <option value="<?php echo esc_attr($ritmo_option['value']); ?>" <?php selected($ritmo_option['selected']); ?>><?php echo esc_html($ritmo_option['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($ritmo_n['kind'] === 'lines') : ?>
                    <textarea id="<?php echo esc_attr($ritmo_n['id']); ?>" name="<?php echo esc_attr($ritmo_n['name']); ?>" rows="3" aria-describedby="<?php echo esc_attr($ritmo_aria); ?>" <?php disabled($ritmo_n['disabled']); ?>><?php echo esc_html($ritmo_n['value']); ?></textarea>
                <?php elseif ($ritmo_n['kind'] === 'number' || $ritmo_n['kind'] === 'percent') : ?>
                    <input type="number" inputmode="decimal" id="<?php echo esc_attr($ritmo_n['id']); ?>" name="<?php echo esc_attr($ritmo_n['name']); ?>" value="<?php echo esc_attr($ritmo_n['value']); ?>" step="<?php echo esc_attr($ritmo_n['step']); ?>" min="<?php echo esc_attr($ritmo_n['min']); ?>" max="<?php echo esc_attr($ritmo_n['max']); ?>" aria-describedby="<?php echo esc_attr($ritmo_aria); ?>" <?php disabled($ritmo_n['disabled']); ?> <?php if ($ritmo_n['error'] !== null) : ?>aria-invalid="true"<?php endif; ?>>
                    <?php if ($ritmo_n['unit'] !== '') : ?><span class="ritmo-unit"><?php echo esc_html($ritmo_n['unit']); ?></span><?php endif; ?>
                <?php elseif ($ritmo_n['kind'] === 'variant') : ?>
                    <span id="<?php echo esc_attr($ritmo_n['id']); ?>"><?php echo esc_html($ritmo_n['value_text']); ?></span>
                <?php else : ?>
                    <input type="<?php echo esc_attr($ritmo_n['kind'] === 'time' ? 'time' : ($ritmo_n['kind'] === 'date' ? 'date' : 'text')); ?>" id="<?php echo esc_attr($ritmo_n['id']); ?>" name="<?php echo esc_attr($ritmo_n['name']); ?>" value="<?php echo esc_attr($ritmo_n['value']); ?>" <?php if (($ritmo_n['maxlength'] ?? '') !== '') : ?>maxlength="<?php echo esc_attr($ritmo_n['maxlength']); ?>"<?php endif; ?> aria-describedby="<?php echo esc_attr($ritmo_aria); ?>" <?php disabled($ritmo_n['disabled']); ?> <?php if ($ritmo_n['error'] !== null) : ?>aria-invalid="true"<?php endif; ?>>
                <?php endif; ?>
            </span>
        <?php endif; ?>
        <p class="ritmo-help" id="<?php echo esc_attr($ritmo_described); ?>">
            <?php if ($ritmo_n['help'] !== '') : ?><?php echo esc_html($ritmo_n['help']); ?><?php endif; ?>
            <?php if (($ritmo_n['min'] ?? '') !== '' && ($ritmo_n['max'] ?? '') !== '') : ?>
                <span class="ritmo-range"><?php echo esc_html(sprintf(/* translators: 1: lowest value, 2: highest value (each with its unit, if any) */ __('Allowed: %1$s to %2$s.', 'ritmo-revenue'), trim($ritmo_n['min'] . ' ' . ($ritmo_n['unit'] ?? '')), trim($ritmo_n['max'] . ' ' . ($ritmo_n['unit'] ?? '')))); ?></span>
            <?php endif; ?>
            <?php if (($ritmo_n['default_text'] ?? '') !== '') : ?>
                <span class="ritmo-default"><?php echo esc_html(sprintf(/* translators: %s: the default value */ __('Default: %s.', 'ritmo-revenue'), $ritmo_n['default_text'])); ?></span>
            <?php endif; ?>
        </p>
        <?php if ($ritmo_n['error'] !== null) : ?><p class="ritmo-field-error" id="<?php echo esc_attr($ritmo_error_id); ?>"><?php echo esc_html($ritmo_n['error']); ?></p><?php endif; ?>
    </div>
<?php endif; ?>
