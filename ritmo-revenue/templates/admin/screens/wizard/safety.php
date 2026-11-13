<?php
/**
 * Wizard step 7: the safety limits, seen and confirmed (property id typed) by a person allowed
 * to change them; read-only for anyone else.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_c = $view['c'];
$ritmo_ro = !$view['can'];
?>
<h2><?php esc_html_e('Safety limits', 'ritmo-revenue'); ?></h2>
<p class="description"><?php echo esc_html($view['safety_note']); ?></p>
<?php if ($ritmo_c['property_id'] === '') : ?>
    <p data-ritmo="empty"><?php esc_html_e('Create the property first (step 4).', 'ritmo-revenue'); ?></p>
<?php else : ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ritmo-form" autocomplete="off">
    <?php wp_nonce_field('ritmo_wizard_confirm_safety'); ?>
    <input type="hidden" name="action" value="ritmo_wizard_confirm_safety">
    <input type="hidden" name="property_id" value="<?php echo esc_attr($ritmo_c['property_id']); ?>">
    <?php foreach ($ritmo_c['rooms'] as $ritmo_i => $ritmo_room) : ?>
        <fieldset class="ritmo-room-card">
            <legend><?php echo esc_html($ritmo_room['name']); ?></legend>
            <p><?php esc_html_e('Listing minimum:', 'ritmo-revenue'); ?> <?php View::render('admin/screens/parts/price', ['price' => $ritmo_room['listing_min']]); ?></p>
            <input type="hidden" name="<?php echo esc_attr('reference[' . $ritmo_i . '][code]'); ?>" value="<?php echo esc_attr($ritmo_room['code']); ?>">
            <p><label for="<?php echo esc_attr('ritmo-ref-' . $ritmo_i); ?>"><?php /* translators: %s: room name */ echo esc_html(sprintf(__('Reference minimum of %s', 'ritmo-revenue'), $ritmo_room['name'])); ?> (<?php echo esc_html($ritmo_c['symbol']); ?>)</label>
                <input type="number" step="0.01" min="0" id="<?php echo esc_attr('ritmo-ref-' . $ritmo_i); ?>" name="<?php echo esc_attr('reference[' . $ritmo_i . '][amount]'); ?>" value="<?php echo esc_attr($ritmo_room['reference']); ?>" <?php disabled($ritmo_ro); ?>></p>
            <p class="ritmo-muted"><?php echo esc_html($ritmo_room['floor']); ?></p>
        </fieldset>
    <?php endforeach; ?>
    <fieldset>
        <legend><?php esc_html_e('Limits of the whole site', 'ritmo-revenue'); ?></legend>
        <p><label for="ritmo-s-fraction"><?php esc_html_e('No price below this share of the reference minimum (%)', 'ritmo-revenue'); ?></label>
            <input type="text" inputmode="decimal" id="ritmo-s-fraction" name="fraction" value="<?php echo esc_attr($ritmo_c['fraction']); ?>" <?php disabled($ritmo_ro); ?>>
            <span class="description"><?php esc_html_e('Default 75.', 'ritmo-revenue'); ?></span></p>
        <p><label for="ritmo-s-weekly"><?php esc_html_e('Largest change of a listing value in a week (%)', 'ritmo-revenue'); ?></label>
            <input type="text" inputmode="decimal" id="ritmo-s-weekly" name="weekly" value="<?php echo esc_attr($ritmo_c['weekly']); ?>" <?php disabled($ritmo_ro); ?>>
            <span class="description"><?php esc_html_e('Default 10.', 'ritmo-revenue'); ?></span></p>
        <p><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e('Never raise tonight\'s price: always on.', 'ritmo-revenue'); ?></p>
        <p><label><input type="checkbox" name="listing_changes" value="1" <?php checked($ritmo_c['listing_changes']); ?> <?php disabled($ritmo_ro); ?>> <?php esc_html_e('Allow changes of a listing\'s minimum, base and maximum (still only with a person\'s approval). Default off.', 'ritmo-revenue'); ?></label></p>
        <p><label><input type="checkbox" name="autonomous" value="1" <?php checked($ritmo_c['autonomous']); ?> <?php disabled($ritmo_ro); ?>> <?php esc_html_e('Allow the autonomous mode at all. Default off.', 'ritmo-revenue'); ?></label></p>
    </fieldset>
    <?php if (!$ritmo_ro) : ?>
        <p class="ritmo-confirm" data-ritmo-js="confirm-typed" data-ritmo="safety-confirm">
            <label for="ritmo-s-confirm"><?php esc_html_e('To confirm, type the property id:', 'ritmo-revenue'); ?> <code><?php echo esc_html($ritmo_c['property_id']); ?></code></label>
            <input type="text" id="ritmo-s-confirm" name="confirmation" autocomplete="off" data-expected="<?php echo esc_attr($ritmo_c['property_id']); ?>">
        </p>
        <button type="submit" class="button button-primary" data-ritmo="wizard-continue"><?php esc_html_e('Confirm the safety limits', 'ritmo-revenue'); ?></button>
    <?php endif; ?>
</form>
<?php endif; ?>
