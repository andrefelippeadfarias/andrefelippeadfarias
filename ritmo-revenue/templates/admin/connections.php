<?php
/**
 * Connections: keys of Beds24 and PriceLabs (write-only fields) and the connection tests.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_secret_state = static function ($status): string {
    if (!$status->configured) {
        return __('Not configured.', 'ritmo-revenue');
    }

    return $status->lastFour === ''
        ? __('Configured.', 'ritmo-revenue')
        /* translators: %s: last four characters of the saved key */
        : sprintf(__('Configured, ends with %s.', 'ritmo-revenue'), $status->lastFour);
};
$ritmo_last_test = static function ($test): string {
    if (!is_array($test)) {
        return __('Not tested yet.', 'ritmo-revenue');
    }

    /* translators: 1: OK or Failed, 2: answer of the test, 3: date and time */
    return sprintf(__('%1$s: %2$s (%3$s)', 'ritmo-revenue'), $test['ok'] ? __('OK', 'ritmo-revenue') : __('Failed', 'ritmo-revenue'), (string) $test['message'], wp_date('Y-m-d H:i', strtotime((string) $test['at'])));
};

View::render('admin/partials/header', $view);
?>
<form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card ritmo-form" autocomplete="off">
    <?php wp_nonce_field('ritmo_save_connections'); ?>
    <input type="hidden" name="action" value="ritmo_save_connections">
    <h2><?php esc_html_e('Beds24 (PMS)', 'ritmo-revenue'); ?></h2>
    <p class="description"><?php esc_html_e('Read only in this version: bookings, availability and the calendar. Keys are stored encrypted and never shown again.', 'ritmo-revenue'); ?></p>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="ritmo-beds24-kind"><?php esc_html_e('Token type', 'ritmo-revenue'); ?></label></th>
            <td>
                <select id="ritmo-beds24-kind" name="beds24_token_kind">
                    <option value="refresh" <?php selected($view['beds24']['token_kind'], 'refresh'); ?>><?php esc_html_e('Refresh token', 'ritmo-revenue'); ?></option>
                    <option value="long_life" <?php selected($view['beds24']['token_kind'], 'long_life'); ?>><?php esc_html_e('Long-life token', 'ritmo-revenue'); ?></option>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ritmo-beds24-token"><?php esc_html_e('Token', 'ritmo-revenue'); ?></label></th>
            <td>
                <input type="password" id="ritmo-beds24-token" name="beds24_token" class="regular-text" value="" autocomplete="new-password">
                <p class="description" data-ritmo="beds24-secret"><?php echo esc_html($ritmo_secret_state($view['beds24']['secret'])); ?> <?php esc_html_e('Leave empty to keep it.', 'ritmo-revenue'); ?></p>
                <?php if ($view['beds24']['secret']->configured) : ?>
                    <label><input type="checkbox" name="forget_beds24_token" value="1"> <?php esc_html_e('Remove the saved token', 'ritmo-revenue'); ?></label>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ritmo-beds24-properties"><?php esc_html_e('Beds24 property ids', 'ritmo-revenue'); ?></label></th>
            <td>
                <input type="text" id="ritmo-beds24-properties" name="beds24_property_ids" class="regular-text" value="<?php echo esc_attr($view['beds24']['property_ids']); ?>">
                <p class="description"><?php esc_html_e('Comma separated. Empty reads every property the token can see.', 'ritmo-revenue'); ?></p>
            </td>
        </tr>
    </table>

    <h2><?php esc_html_e('PriceLabs (pricing system)', 'ritmo-revenue'); ?></h2>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="ritmo-pricelabs-key"><?php esc_html_e('API key', 'ritmo-revenue'); ?></label></th>
            <td>
                <input type="password" id="ritmo-pricelabs-key" name="pricelabs_api_key" class="regular-text" value="" autocomplete="new-password">
                <p class="description" data-ritmo="pricelabs-secret"><?php echo esc_html($ritmo_secret_state($view['pricelabs']['secret'])); ?> <?php esc_html_e('Leave empty to keep it.', 'ritmo-revenue'); ?></p>
                <?php if ($view['pricelabs']['secret']->configured) : ?>
                    <label><input type="checkbox" name="forget_pricelabs_api_key" value="1"> <?php esc_html_e('Remove the saved key', 'ritmo-revenue'); ?></label>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ritmo-pricelabs-pms"><?php esc_html_e('PMS name in PriceLabs', 'ritmo-revenue'); ?></label></th>
            <td><input type="text" id="ritmo-pricelabs-pms" name="pricelabs_pms" class="regular-text" value="<?php echo esc_attr($view['pricelabs']['pms']); ?>"></td>
        </tr>
    </table>
    <p class="description">
        <?php if ($view['calendar_read']) : ?>
            <?php esc_html_e('The pricing calendar is read from PriceLabs (allowed on this site by RITMO_PRICING_CALENDAR_READ; that read uses POST).', 'ritmo-revenue'); ?>
        <?php else : ?>
            <?php esc_html_e('Current prices come from the PMS calendar; the PriceLabs calendar, a read its API serves by POST, is not used.', 'ritmo-revenue'); ?>
        <?php endif; ?>
    </p>
    <?php submit_button(__('Save connections', 'ritmo-revenue'), 'primary', 'save_connections'); ?>
</form>

<div class="ritmo-grid">
    <?php foreach (['beds24' => 'Beds24', 'pricelabs' => 'PriceLabs'] as $ritmo_provider => $ritmo_label) : ?>
        <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card">
            <?php wp_nonce_field('ritmo_test_connection'); ?>
            <input type="hidden" name="action" value="ritmo_test_connection">
            <input type="hidden" name="provider" value="<?php echo esc_attr($ritmo_provider); ?>">
            <h3><?php echo esc_html($ritmo_label); ?></h3>
            <p data-ritmo="test-<?php echo esc_attr($ritmo_provider); ?>"><?php echo esc_html($ritmo_last_test($view[$ritmo_provider]['last_test'])); ?></p>
            <?php submit_button(__('Test connection', 'ritmo-revenue'), 'secondary', 'test_' . $ritmo_provider, false, ['data-ritmo' => 'test-' . $ritmo_provider . '-button']); ?>
        </form>
    <?php endforeach; ?>
</div>
<?php View::render('admin/partials/footer', $view); ?>
