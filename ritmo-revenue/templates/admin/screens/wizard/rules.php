<?php
/**
 * Wizard step 6: the conservative rules or a preset file, and the weekend nights.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$ritmo_c = $view['c'];
?>
<h2><?php esc_html_e('Rules and calendar', 'ritmo-revenue'); ?></h2>
<?php if ($view['can']) : ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="ritmo-form">
    <?php wp_nonce_field('ritmo_wizard_save_rules'); ?>
    <input type="hidden" name="action" value="ritmo_wizard_save_rules">
    <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
    <fieldset>
        <legend><?php esc_html_e('Rules', 'ritmo-revenue'); ?></legend>
        <p><label><input type="radio" name="rules" value="conservative" checked> <?php esc_html_e('Conservative (recommended)', 'ritmo-revenue'); ?></label></p>
        <p><label><input type="radio" name="rules" value="import"> <?php esc_html_e('Import a preset file', 'ritmo-revenue'); ?></label>
            <label for="ritmo-preset" class="screen-reader-text"><?php esc_html_e('Preset file', 'ritmo-revenue'); ?></label>
            <input type="file" id="ritmo-preset" name="preset_file" accept=".json,application/json"></p>
    </fieldset>
    <fieldset data-ritmo="weekend-days">
        <legend><?php esc_html_e('Weekend nights', 'ritmo-revenue'); ?></legend>
        <?php foreach ($ritmo_c['days'] as $ritmo_day) : ?>
            <label class="ritmo-day"><input type="checkbox" name="weekend[]" value="<?php echo esc_attr((string) $ritmo_day['value']); ?>" <?php checked($ritmo_day['checked']); ?>> <?php echo esc_html($ritmo_day['name']); ?></label>
        <?php endforeach; ?>
    </fieldset>
    <p class="description"><?php esc_html_e('Saving creates a new version of the rules. It applies from the next run and never writes a price.', 'ritmo-revenue'); ?></p>
    <button type="submit" class="button button-primary" data-ritmo="wizard-continue"><?php esc_html_e('Save and continue', 'ritmo-revenue'); ?></button>
</form>
<?php endif; ?>
