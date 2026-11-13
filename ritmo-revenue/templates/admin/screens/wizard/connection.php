<?php
/**
 * Wizard steps 2 and 3: the key of the PMS or of the pricing system, saved to the vault and tested at once.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$ritmo_c = $view['c'];
?>
<h2><?php echo esc_html($ritmo_c['provider'] === 'beds24' ? __('Connect the PMS', 'ritmo-revenue') : __('Connect the pricing system', 'ritmo-revenue')); ?></h2>
<?php if ($ritmo_c['result'] !== '') : ?>
    <p class="ritmo-badge ritmo-tone-<?php echo esc_attr($ritmo_c['ok'] ? 'good' : 'critical'); ?>" role="status" data-ritmo="connection-result-<?php echo esc_attr($ritmo_c['provider']); ?>"><?php echo esc_html($ritmo_c['result']); ?></p>
<?php endif; ?>
<?php if ($view['can']) : ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off" class="ritmo-form">
    <?php wp_nonce_field($ritmo_c['provider'] === 'beds24' ? 'ritmo_wizard_save_pms' : 'ritmo_wizard_save_pricing'); ?>
    <input type="hidden" name="action" value="<?php echo esc_attr($ritmo_c['provider'] === 'beds24' ? 'ritmo_wizard_save_pms' : 'ritmo_wizard_save_pricing'); ?>">
    <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
    <?php if ($ritmo_c['provider'] === 'beds24') : ?>
        <p class="description"><?php esc_html_e('Create the token in the PMS, in its settings of the API, with access to bookings, properties and the inventory.', 'ritmo-revenue'); ?></p>
        <p><label for="ritmo-w-kind"><?php esc_html_e('Kind of token', 'ritmo-revenue'); ?></label>
            <select id="ritmo-w-kind" name="beds24_token_kind">
                <option value="refresh" <?php selected('refresh', $ritmo_c['token_kind']); ?>><?php esc_html_e('Refresh token', 'ritmo-revenue'); ?></option>
                <option value="long_life" <?php selected('long_life', $ritmo_c['token_kind']); ?>><?php esc_html_e('Long-life token', 'ritmo-revenue'); ?></option>
            </select></p>
        <p><label for="ritmo-w-token"><?php esc_html_e('Token', 'ritmo-revenue'); ?></label>
            <input type="password" id="ritmo-w-token" name="beds24_token" class="regular-text" autocomplete="new-password" data-ritmo="beds24-secret">
            <span class="description"><?php echo esc_html($ritmo_c['saved'] ? __('A token is saved. Leave empty to keep it.', 'ritmo-revenue') : __('No token saved yet.', 'ritmo-revenue')); ?></span></p>
        <p><label for="ritmo-w-ids"><?php esc_html_e('Property ids in the PMS (comma separated)', 'ritmo-revenue'); ?></label>
            <input type="text" id="ritmo-w-ids" name="beds24_property_ids" class="regular-text" value="<?php echo esc_attr($ritmo_c['property_ids']); ?>"></p>
    <?php else : ?>
        <p class="description"><?php esc_html_e('Create the API key in the pricing system, in its account settings.', 'ritmo-revenue'); ?></p>
        <p><label for="ritmo-w-key"><?php esc_html_e('API key', 'ritmo-revenue'); ?></label>
            <input type="password" id="ritmo-w-key" name="pricelabs_api_key" class="regular-text" autocomplete="new-password">
            <span class="description"><?php echo esc_html($ritmo_c['saved'] ? __('A key is saved. Leave empty to keep it.', 'ritmo-revenue') : __('No key saved yet.', 'ritmo-revenue')); ?></span></p>
        <p><label for="ritmo-w-pms"><?php esc_html_e('Name of your PMS in the pricing system', 'ritmo-revenue'); ?></label>
            <input type="text" id="ritmo-w-pms" name="pricelabs_pms" value="<?php echo esc_attr($ritmo_c['pms']); ?>"></p>
    <?php endif; ?>
    <button type="submit" class="button button-primary" data-ritmo="wizard-continue"><?php esc_html_e('Save and test', 'ritmo-revenue'); ?></button>
</form>
<?php endif; ?>
