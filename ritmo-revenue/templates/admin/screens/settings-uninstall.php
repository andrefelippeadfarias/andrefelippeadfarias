<?php
/**
 * Settings › Uninstall: keep or delete the plugin's data when it is uninstalled.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ritmo-card ritmo-form">
    <?php wp_nonce_field('ritmo_save_settings'); ?>
    <input type="hidden" name="action" value="ritmo_save_settings">
    <h2><?php esc_html_e('When the plugin is uninstalled', 'ritmo-revenue'); ?></h2>
    <p><label><input type="checkbox" name="delete_data" value="1" <?php checked($view['delete']); ?>> <?php esc_html_e('Delete every table, setting and saved key of the plugin', 'ritmo-revenue'); ?></label></p>
    <p class="description"><?php esc_html_e('Either way, uninstalling frees the licence and deletes the saved keys and tokens (PMS, pricing system, Claude, licence). Kept data has no secret: connect again after reinstalling.', 'ritmo-revenue'); ?></p>
    <button type="submit" class="button"><?php esc_html_e('Save', 'ritmo-revenue'); ?></button>
</form>
