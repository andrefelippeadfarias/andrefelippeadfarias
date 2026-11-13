<?php
/**
 * Wizard step 4: the property (always created in shadow mode).
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$ritmo_c = $view['c'];
?>
<h2><?php esc_html_e('Property', 'ritmo-revenue'); ?></h2>
<?php if ($view['can']) : ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ritmo-form">
    <?php wp_nonce_field('ritmo_wizard_save_property'); ?>
    <input type="hidden" name="action" value="ritmo_wizard_save_property">
    <p><label for="ritmo-p-name"><?php esc_html_e('Name', 'ritmo-revenue'); ?></label>
        <input type="text" id="ritmo-p-name" name="name" maxlength="100" required class="regular-text"></p>
    <p><label for="ritmo-p-id"><?php esc_html_e('Id (letters, digits, "-" and "_"; it cannot be changed later)', 'ritmo-revenue'); ?></label>
        <input type="text" id="ritmo-p-id" name="id" maxlength="32" required pattern="[A-Za-z0-9_-]{1,32}" data-ritmo-js="slug-from" data-source="ritmo-p-name"></p>
    <p><label for="ritmo-p-tz"><?php esc_html_e('Timezone', 'ritmo-revenue'); ?></label>
        <select id="ritmo-p-tz" name="timezone" required>
            <option value=""><?php esc_html_e('Choose…', 'ritmo-revenue'); ?></option>
            <?php foreach ($ritmo_c['timezones'] as $ritmo_tz) : ?><option value="<?php echo esc_attr($ritmo_tz); ?>" <?php selected($ritmo_tz, $ritmo_c['timezone']); ?>><?php echo esc_html($ritmo_tz); ?></option><?php endforeach; ?>
        </select></p>
    <p><label for="ritmo-p-cur"><?php esc_html_e('Currency', 'ritmo-revenue'); ?></label>
        <select id="ritmo-p-cur" name="currency">
            <?php foreach ($ritmo_c['currencies'] as $ritmo_cur) : ?><option value="<?php echo esc_attr($ritmo_cur); ?>"><?php echo esc_html($ritmo_cur); ?></option><?php endforeach; ?>
        </select></p>
    <p><label for="ritmo-p-tier"><?php esc_html_e('Tier', 'ritmo-revenue'); ?></label>
        <input type="text" id="ritmo-p-tier" name="tier" value="standard" maxlength="32"></p>
    <button type="submit" class="button button-primary" data-ritmo="wizard-continue"><?php esc_html_e('Save and continue', 'ritmo-revenue'); ?></button>
</form>
<?php endif; ?>
