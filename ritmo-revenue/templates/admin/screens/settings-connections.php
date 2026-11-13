<?php
/**
 * Settings › Connections: per system what the plugin does, the key (never shown), its test and
 * "forget the key"; the keys are saved with the M1 form.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ritmo-card ritmo-wide ritmo-form" autocomplete="off">
    <?php wp_nonce_field('ritmo_save_connections'); ?>
    <input type="hidden" name="action" value="ritmo_save_connections">
    <?php foreach ($view['providers'] as $ritmo_id => $ritmo_p) : ?>
        <section data-ritmo="connection-<?php echo esc_attr($ritmo_id); ?>">
            <h2><?php echo esc_html($ritmo_p['name']); ?> — <?php echo esc_html($ritmo_id === 'beds24' ? __('PMS', 'ritmo-revenue') : __('pricing system', 'ritmo-revenue')); ?></h2>
            <p><?php echo esc_html($ritmo_p['does']); ?></p>
            <p><strong><?php esc_html_e('Key:', 'ritmo-revenue'); ?></strong> <?php echo esc_html($ritmo_p['saved']); ?></p>
            <?php if ($ritmo_id === 'beds24') : ?>
                <p><label for="ritmo-c-token"><?php esc_html_e('Replace the token', 'ritmo-revenue'); ?></label>
                    <input type="password" id="ritmo-c-token" name="beds24_token" class="regular-text" autocomplete="new-password" data-ritmo="beds24-secret">
                    <span class="description"><?php esc_html_e('Leave empty to keep the saved key.', 'ritmo-revenue'); ?></span></p>
                <p><label for="ritmo-c-kind"><?php esc_html_e('Kind of token', 'ritmo-revenue'); ?></label>
                    <select id="ritmo-c-kind" name="beds24_token_kind">
                        <option value="refresh" <?php selected('refresh', $view['token_kind']); ?>><?php esc_html_e('Refresh token', 'ritmo-revenue'); ?></option>
                        <option value="long_life" <?php selected('long_life', $view['token_kind']); ?>><?php esc_html_e('Long-life token', 'ritmo-revenue'); ?></option>
                    </select></p>
                <p><label for="ritmo-c-ids"><?php esc_html_e('Property ids in the PMS (comma separated)', 'ritmo-revenue'); ?></label>
                    <input type="text" id="ritmo-c-ids" name="beds24_property_ids" value="<?php echo esc_attr($view['property_ids']); ?>"></p>
            <?php else : ?>
                <p><label for="ritmo-c-key"><?php esc_html_e('Replace the API key', 'ritmo-revenue'); ?></label>
                    <input type="password" id="ritmo-c-key" name="pricelabs_api_key" class="regular-text" autocomplete="new-password">
                    <span class="description"><?php esc_html_e('Leave empty to keep the saved key.', 'ritmo-revenue'); ?></span></p>
                <p><label for="ritmo-c-pms"><?php esc_html_e('Name of your PMS in the pricing system', 'ritmo-revenue'); ?></label>
                    <input type="text" id="ritmo-c-pms" name="pricelabs_pms" value="<?php echo esc_attr($view['pms']); ?>"></p>
                <p class="description"><?php echo esc_html($view['calendar_read'] ? __('The calendar of the pricing system is read in every run.', 'ritmo-revenue') : __('The calendar of the pricing system is not read (switched off on this site).', 'ritmo-revenue')); ?></p>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    <button type="submit" class="button button-primary"><?php esc_html_e('Save the connections', 'ritmo-revenue'); ?></button>
</form>

<?php foreach ($view['providers'] as $ritmo_id => $ritmo_p) : ?>
    <section class="ritmo-card">
        <h2><?php /* translators: %s: Beds24 or PriceLabs */ echo esc_html(sprintf(__('Test %s', 'ritmo-revenue'), $ritmo_p['name'])); ?></h2>
        <p role="status" data-ritmo="connection-result-<?php echo esc_attr($ritmo_id); ?>"><?php echo esc_html($ritmo_p['last']); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ritmo-js="async-form" data-rest-url="<?php echo esc_url($view['rest_url'] . 'connections/' . $ritmo_id . '/test'); ?>" data-rest-nonce="<?php echo esc_attr($view['rest_nonce']); ?>" data-result="connection-result-<?php echo esc_attr($ritmo_id); ?>">
            <?php wp_nonce_field('ritmo_connection_test'); ?>
            <input type="hidden" name="action" value="ritmo_connection_test">
            <input type="hidden" name="provider" value="<?php echo esc_attr($ritmo_id); ?>">
            <input type="hidden" name="tab" value="connections">
            <button type="submit" class="button" data-ritmo="connection-test-<?php echo esc_attr($ritmo_id); ?>"><?php esc_html_e('Test now', 'ritmo-revenue'); ?></button>
            <span class="spinner" aria-hidden="true"></span>
        </form>
        <?php if ($ritmo_p['configured']) : ?>
            <details>
                <summary><?php esc_html_e('Forget the key', 'ritmo-revenue'); ?></summary>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ritmo="forget-<?php echo esc_attr($ritmo_id); ?>" autocomplete="off">
                    <?php wp_nonce_field('ritmo_forget_secret'); ?>
                    <input type="hidden" name="action" value="ritmo_forget_secret">
                    <input type="hidden" name="provider" value="<?php echo esc_attr($ritmo_id); ?>">
                    <p class="ritmo-confirm" data-ritmo-js="confirm-typed">
                        <label for="ritmo-forget-<?php echo esc_attr($ritmo_id); ?>"><?php esc_html_e('To confirm, type the name of the system:', 'ritmo-revenue'); ?> <code><?php echo esc_html($ritmo_p['name']); ?></code></label>
                        <input type="text" id="ritmo-forget-<?php echo esc_attr($ritmo_id); ?>" name="confirmation" data-expected="<?php echo esc_attr($ritmo_p['name']); ?>">
                    </p>
                    <button type="submit" class="button button-link-delete"><?php esc_html_e('Forget the key', 'ritmo-revenue'); ?></button>
                </form>
            </details>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
