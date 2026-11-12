<?php
/**
 * Minimal setup: properties, room types and their mapping, preset import, competitor prices.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

View::render('admin/partials/header', $view);
?>
<section class="ritmo-card">
    <h2><?php esc_html_e('Properties', 'ritmo-revenue'); ?></h2>
    <?php if ($view['properties'] === []) : ?>
        <p><?php esc_html_e('No property yet.', 'ritmo-revenue'); ?></p>
    <?php else : ?>
        <table class="widefat striped ritmo-table" data-ritmo="properties">
            <thead><tr>
                <th scope="col"><?php esc_html_e('Id', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Name', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Timezone', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Currency', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Tier', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Mode', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Room types (PMS / pricing)', 'ritmo-revenue'); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($view['properties'] as $ritmo_property) : ?>
                    <tr>
                        <td><code><?php echo esc_html($ritmo_property['id']); ?></code></td>
                        <td><?php echo esc_html($ritmo_property['name']); ?></td>
                        <td><?php echo esc_html($ritmo_property['timezone']); ?></td>
                        <td><?php echo esc_html($ritmo_property['currency']); ?></td>
                        <td><?php echo esc_html($ritmo_property['tier']); ?></td>
                        <td><?php echo esc_html($ritmo_property['mode']); ?></td>
                        <td>
                            <?php foreach ($view['rooms'][$ritmo_property['id']] ?? [] as $ritmo_room) : ?>
                                <div><code><?php echo esc_html($ritmo_room['code']); ?></code> × <?php echo esc_html((string) $ritmo_room['units']); ?>
                                    → <?php echo esc_html($ritmo_room['pms_ref'] === '' ? '–' : $ritmo_room['pms_ref']); ?> / <?php echo esc_html($ritmo_room['pricing_ref'] === '' ? '–' : $ritmo_room['pricing_ref']); ?></div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<div class="ritmo-grid">
    <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card ritmo-form">
        <?php wp_nonce_field('ritmo_create_property'); ?>
        <input type="hidden" name="action" value="ritmo_create_property">
        <h3><?php esc_html_e('Create a property', 'ritmo-revenue'); ?></h3>
        <p><label><?php esc_html_e('Id (short code)', 'ritmo-revenue'); ?><br><input type="text" name="id" required pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,31}"></label></p>
        <p><label><?php esc_html_e('Name', 'ritmo-revenue'); ?><br><input type="text" name="name" required maxlength="100"></label></p>
        <p><label><?php esc_html_e('Timezone', 'ritmo-revenue'); ?><br>
            <select name="timezone" required>
                <?php foreach ($view['timezones'] as $ritmo_zone) : ?>
                    <option value="<?php echo esc_attr($ritmo_zone); ?>" <?php selected($ritmo_zone, wp_timezone_string()); ?>><?php echo esc_html($ritmo_zone); ?></option>
                <?php endforeach; ?>
            </select></label></p>
        <p><label><?php esc_html_e('Currency', 'ritmo-revenue'); ?><br><input type="text" name="currency" required maxlength="3" size="4" pattern="[A-Za-z]{3}"></label></p>
        <p><label><?php esc_html_e('Tier', 'ritmo-revenue'); ?><br><input type="text" name="tier" value="standard"></label></p>
        <?php submit_button(__('Create property', 'ritmo-revenue'), 'primary', 'create_property', false); ?>
    </form>

    <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card ritmo-form">
        <?php wp_nonce_field('ritmo_save_room_type'); ?>
        <input type="hidden" name="action" value="ritmo_save_room_type">
        <h3><?php esc_html_e('Map a room type', 'ritmo-revenue'); ?></h3>
        <p class="description"><?php esc_html_e('Saving an existing code replaces it. Amounts are the listing values in the plan the pricing system sends.', 'ritmo-revenue'); ?></p>
        <p><label><?php esc_html_e('Property', 'ritmo-revenue'); ?><br>
            <select name="property_id" required>
                <?php foreach ($view['properties'] as $ritmo_property) : ?>
                    <option value="<?php echo esc_attr($ritmo_property['id']); ?>"><?php echo esc_html($ritmo_property['name']); ?></option>
                <?php endforeach; ?>
            </select></label></p>
        <p><label><?php esc_html_e('Code', 'ritmo-revenue'); ?><br><input type="text" name="code" required></label>
           <label><?php esc_html_e('Name', 'ritmo-revenue'); ?><br><input type="text" name="name" required></label>
           <label><?php esc_html_e('Units', 'ritmo-revenue'); ?><br><input type="number" name="units" min="1" required></label></p>
        <p><label><?php esc_html_e('PMS room id', 'ritmo-revenue'); ?><br><input type="text" name="pms_ref"></label>
           <label><?php esc_html_e('Pricing listing id', 'ritmo-revenue'); ?><br><input type="text" name="pricing_ref"></label></p>
        <p><label><?php esc_html_e('Base', 'ritmo-revenue'); ?><br><input type="text" name="base" inputmode="decimal" required></label>
           <label><?php esc_html_e('Minimum', 'ritmo-revenue'); ?><br><input type="text" name="min" inputmode="decimal" required></label>
           <label><?php esc_html_e('Maximum', 'ritmo-revenue'); ?><br><input type="text" name="max" inputmode="decimal" required></label>
           <label><?php esc_html_e('Reference minimum', 'ritmo-revenue'); ?><br><input type="text" name="reference_min" inputmode="decimal" required></label></p>
        <p><label><?php esc_html_e('Tags (comma separated)', 'ritmo-revenue'); ?><br><input type="text" name="tags"></label></p>
        <?php submit_button(__('Save room type', 'ritmo-revenue'), 'primary', 'save_room_type', false); ?>
    </form>
</div>

<div class="ritmo-grid">
    <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card ritmo-form" enctype="multipart/form-data">
        <?php wp_nonce_field('ritmo_import_preset'); ?>
        <input type="hidden" name="action" value="ritmo_import_preset">
        <h3><?php esc_html_e('Import a preset', 'ritmo-revenue'); ?></h3>
        <p class="description">
            <?php
            /* translators: %s: version number or a dash */
            echo esc_html(sprintf(__('Rules in force: version %s. A preset adds a new version; its properties are created in shadow mode.', 'ritmo-revenue'), $view['rules_version'] === null ? '–' : (string) $view['rules_version']));
            ?>
        </p>
        <p><input type="file" name="preset_file" accept="application/json,.json"></p>
        <p><textarea name="preset_json" rows="6" class="large-text code" placeholder="{&quot;format&quot;: &quot;ritmo-revenue-preset&quot;, ...}"></textarea></p>
        <?php submit_button(__('Import preset', 'ritmo-revenue'), 'secondary', 'import_preset', false); ?>
    </form>

    <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card ritmo-form">
        <?php wp_nonce_field('ritmo_import_samples'); ?>
        <input type="hidden" name="action" value="ritmo_import_samples">
        <h3><?php esc_html_e('Competitor prices', 'ritmo-revenue'); ?></h3>
        <p class="description"><?php esc_html_e('One "date,price" per line (YYYY-MM-DD and the public nightly price). Each paste is a full picture of the market for its nights and replaces the market anchor of those nights.', 'ritmo-revenue'); ?></p>
        <p><label><?php esc_html_e('Property', 'ritmo-revenue'); ?><br>
            <select name="property_id" required>
                <?php foreach ($view['properties'] as $ritmo_property) : ?>
                    <option value="<?php echo esc_attr($ritmo_property['id']); ?>"><?php echo esc_html($ritmo_property['name']); ?></option>
                <?php endforeach; ?>
            </select></label></p>
        <p><textarea name="samples_csv" rows="6" class="large-text code" placeholder="2026-12-24,480"></textarea></p>
        <?php submit_button(__('Record prices', 'ritmo-revenue'), 'secondary', 'record_prices', false); ?>
    </form>
</div>
<?php View::render('admin/partials/footer', $view); ?>
