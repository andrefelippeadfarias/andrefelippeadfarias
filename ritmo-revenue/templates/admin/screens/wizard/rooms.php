<?php
/**
 * Wizard step 5: the rooms, one row each (PMS room id typed until the PMS can list its rooms),
 * saved together: one invalid row refuses the whole step.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$ritmo_c = $view['c'];
?>
<h2><?php esc_html_e('Rooms', 'ritmo-revenue'); ?></h2>
<?php if (!$ritmo_c['has_property']) : ?>
    <p data-ritmo="empty"><?php esc_html_e('Create the property first (step 4).', 'ritmo-revenue'); ?></p>
<?php elseif ($view['can']) : ?>
<p class="description"><?php esc_html_e('One row per room. The listing minimum, base and maximum are the values of the listing in the pricing system. The reference minimum starts at the listing minimum; change it in step 7.', 'ritmo-revenue'); ?></p>
<?php if ($ritmo_c['suggested']) : ?>
    <p class="notice notice-info inline" data-ritmo="rooms-suggested"><?php esc_html_e('The rows below come from the listings of the pricing system. Check the room id in the PMS and the number of units of each room: they are only suggestions.', 'ritmo-revenue'); ?></p>
<?php endif; ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ritmo-form">
    <?php wp_nonce_field('ritmo_wizard_save_rooms'); ?>
    <input type="hidden" name="action" value="ritmo_wizard_save_rooms">
    <input type="hidden" name="property_id" value="<?php echo esc_attr($ritmo_c['property_id']); ?>">
    <div class="ritmo-room-cards">
        <?php foreach ($ritmo_c['rows'] as $ritmo_i => $ritmo_row) : ?>
            <fieldset class="ritmo-room-card" data-ritmo="room-row">
                <legend><?php /* translators: %d: row number */ echo esc_html(sprintf(__('Room %d', 'ritmo-revenue'), $ritmo_i + 1)); ?></legend>
                <?php foreach (['code' => __('Code', 'ritmo-revenue'), 'name' => __('Name', 'ritmo-revenue'), 'units' => __('Units', 'ritmo-revenue'), 'pms_ref' => __('Room id in the PMS', 'ritmo-revenue'), 'pricing_ref' => __('Listing id in the pricing system', 'ritmo-revenue'), 'min' => __('Listing minimum', 'ritmo-revenue'), 'base' => __('Listing base', 'ritmo-revenue'), 'max' => __('Listing maximum', 'ritmo-revenue'), 'tags' => __('Tags (comma separated)', 'ritmo-revenue')] as $ritmo_field => $ritmo_label) : ?>
                    <p><label for="<?php echo esc_attr('ritmo-r-' . $ritmo_i . '-' . $ritmo_field); ?>"><?php echo esc_html($ritmo_label); ?></label>
                        <input type="<?php echo esc_attr(in_array($ritmo_field, ['units', 'min', 'base', 'max'], true) ? 'number' : 'text'); ?>" step="any" min="0" id="<?php echo esc_attr('ritmo-r-' . $ritmo_i . '-' . $ritmo_field); ?>" name="<?php echo esc_attr('rooms[' . $ritmo_i . '][' . $ritmo_field . ']'); ?>" value="<?php echo esc_attr($ritmo_row[$ritmo_field]); ?>"></p>
                <?php endforeach; ?>
                <p><label><input type="checkbox" name="<?php echo esc_attr('rooms[' . $ritmo_i . '][skip]'); ?>" value="1"> <?php esc_html_e('Do not manage this room', 'ritmo-revenue'); ?></label></p>
            </fieldset>
        <?php endforeach; ?>
    </div>
    <button type="submit" class="button button-primary" data-ritmo="wizard-continue"><?php esc_html_e('Save and continue', 'ritmo-revenue'); ?></button>
</form>
<?php endif; ?>
