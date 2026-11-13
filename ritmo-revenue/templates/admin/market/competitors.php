<?php
/**
 * The competitors of the property: list, add, change, archive.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\Market\MarketText;

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
$ritmo_property = $ritmo_page->property?->id ?? '';
$ritmo_config = $view['config'];
$ritmo_editing = null;
foreach ($view['competitors'] as $ritmo_candidate) {
    if ($ritmo_candidate['id'] === $view['edit']) {
        $ritmo_editing = $ritmo_candidate;
    }
}
?>
<?php if ($view['competitors'] === []) : ?>
    <div class="ritmo-empty" data-ritmo="empty"><p><?php esc_html_e('Add the hotels you compare yourself with. Mark as peer the ones closest to you; the peer series use only them.', 'ritmo-revenue'); ?></p></div>
<?php else : ?>
    <table class="widefat striped ritmo-table">
        <caption><?php esc_html_e('Competitors of this property', 'ritmo-revenue'); ?></caption>
        <thead><tr>
            <th scope="col"><?php esc_html_e('Name', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('Code in price files', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('Comparable room', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('Groups', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('Prices, last 30 days', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('Actions', 'ritmo-revenue'); ?></th>
        </tr></thead>
        <tbody>
            <?php foreach ($view['competitors'] as $ritmo_c) : ?>
                <tr data-ritmo="competitor-row">
                    <th scope="row" data-label="<?php esc_attr_e('Name', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_c['name']); ?>
                        <?php if (!$ritmo_c['active']) : ?> <span class="ritmo-badge ritmo-tone-neutral"><?php esc_html_e('archived', 'ritmo-revenue'); ?></span><?php endif; ?>
                        <?php if ($ritmo_c['is_own']) : ?> <span class="ritmo-badge ritmo-tone-info"><?php esc_html_e('your own listing', 'ritmo-revenue'); ?></span><?php endif; ?>
                        <?php if ($ritmo_c['reference'] !== '') : ?><br><span class="ritmo-muted"><?php echo esc_html($ritmo_c['reference']); ?></span><?php endif; ?>
                    </th>
                    <td data-label="<?php esc_attr_e('Code in price files', 'ritmo-revenue'); ?>"><code><?php echo esc_html($ritmo_c['code']); ?></code></td>
                    <td data-label="<?php esc_attr_e('Comparable room', 'ritmo-revenue'); ?>"><?php echo esc_html(MarketText::factor($ritmo_c['factor'], $ritmo_c['factor_source'], $ritmo_config->defaultFactor, $ritmo_config->estimatedFactorCap)); ?><?php if ($ritmo_c['room_label'] !== '') : ?><br><span class="ritmo-muted"><?php echo esc_html($ritmo_c['room_label']); ?></span><?php endif; ?></td>
                    <td data-label="<?php esc_attr_e('Groups', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_c['tags'] === [] ? '—' : implode(', ', $ritmo_c['tags'])); ?></td>
                    <td data-label="<?php esc_attr_e('Prices, last 30 days', 'ritmo-revenue'); ?>"><?php echo esc_html((string) $ritmo_c['prices_30_days']); ?></td>
                    <td data-label="<?php esc_attr_e('Actions', 'ritmo-revenue'); ?>">
                        <?php if ($view['may_edit']) : ?>
                            <a class="button" href="<?php echo esc_url($ritmo_page->url(['edit' => $ritmo_c['id']])); ?>"><?php esc_html_e('Change', 'ritmo-revenue'); ?></a>
                            <?php if ($ritmo_c['active']) : ?>
                                <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-inline">
                                    <?php wp_nonce_field('ritmo_competitor_archive:' . $ritmo_c['id']); ?>
                                    <input type="hidden" name="action" value="ritmo_competitor_archive">
                                    <input type="hidden" name="competitor" value="<?php echo esc_attr((string) $ritmo_c['id']); ?>">
                                    <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
                                    <input type="hidden" name="tab" value="competitors">
                                    <button type="submit" class="button"><?php esc_html_e('Archive', 'ritmo-revenue'); ?></button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($view['may_edit']) : ?>
    <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card ritmo-form">
        <?php wp_nonce_field('ritmo_competitor_save'); ?>
        <input type="hidden" name="action" value="ritmo_competitor_save">
        <input type="hidden" name="competitor" value="<?php echo esc_attr((string) ($ritmo_editing['id'] ?? 0)); ?>">
        <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
        <input type="hidden" name="tab" value="competitors">
        <h2><?php echo esc_html($ritmo_editing === null ? __('Add a competitor', 'ritmo-revenue') : __('Change a competitor', 'ritmo-revenue')); ?></h2>
        <p><label for="ritmo-c-name"><?php esc_html_e('Name', 'ritmo-revenue'); ?></label><br><input type="text" id="ritmo-c-name" name="competitor_fields[name]" maxlength="120" required value="<?php echo esc_attr($ritmo_editing['name'] ?? ''); ?>"></p>
        <p><label for="ritmo-c-code"><?php esc_html_e('Code used in price files (optional; made from the name)', 'ritmo-revenue'); ?></label><br><input type="text" id="ritmo-c-code" name="competitor_fields[code]" maxlength="64" pattern="[a-z0-9][a-z0-9-]*" value="<?php echo esc_attr($ritmo_editing['code'] ?? ''); ?>"></p>
        <p><label for="ritmo-c-ref"><?php esc_html_e('Reference (shown as text, never opened)', 'ritmo-revenue'); ?></label><br><input type="text" id="ritmo-c-ref" name="competitor_fields[reference]" maxlength="190" value="<?php echo esc_attr($ritmo_editing['reference'] ?? ''); ?>"></p>
        <p><label for="ritmo-c-room"><?php esc_html_e('Room compared', 'ritmo-revenue'); ?></label><br><input type="text" id="ritmo-c-room" name="competitor_fields[room_label]" maxlength="190" value="<?php echo esc_attr($ritmo_editing['room_label'] ?? ''); ?>"></p>
        <p><label for="ritmo-c-tags"><?php esc_html_e('Groups (words separated by commas, such as peer)', 'ritmo-revenue'); ?></label><br><input type="text" id="ritmo-c-tags" name="competitor_fields[tags]" maxlength="190" value="<?php echo esc_attr(implode(', ', $ritmo_editing['tags'] ?? [])); ?>"></p>
        <p><label for="ritmo-c-factor"><?php esc_html_e('Comparable room costs how many times the cheapest room (optional, such as 1.4)', 'ritmo-revenue'); ?></label><br><input type="text" inputmode="decimal" id="ritmo-c-factor" name="competitor_fields[factor]" value="<?php echo esc_attr(isset($ritmo_editing['factor']) ? (string) $ritmo_editing['factor'] : ''); ?>"></p>
        <p><label for="ritmo-c-source"><?php esc_html_e('This factor was', 'ritmo-revenue'); ?></label><br>
            <select id="ritmo-c-source" name="competitor_fields[factor_source]">
                <option value="" <?php selected($ritmo_editing['factor_source'] ?? '', ''); ?>><?php esc_html_e('Not said', 'ritmo-revenue'); ?></option>
                <option value="measured" <?php selected($ritmo_editing['factor_source'] ?? '', 'measured'); ?>><?php esc_html_e('Measured on real prices', 'ritmo-revenue'); ?></option>
                <option value="estimated" <?php selected($ritmo_editing['factor_source'] ?? '', 'estimated'); ?>><?php esc_html_e('Estimated', 'ritmo-revenue'); ?></option>
            </select></p>
        <p><label for="ritmo-c-rating"><?php esc_html_e('Rating (optional, 0 to 10; shown only)', 'ritmo-revenue'); ?></label><br><input type="text" inputmode="decimal" id="ritmo-c-rating" name="competitor_fields[rating]" value="<?php echo esc_attr(isset($ritmo_editing['rating']) ? (string) $ritmo_editing['rating'] : ''); ?>"></p>
        <p><input type="hidden" name="competitor_fields[is_own]" value="0"><label class="ritmo-check"><input type="checkbox" name="competitor_fields[is_own]" value="1" <?php checked($ritmo_editing['is_own'] ?? false); ?>> <?php esc_html_e('This is my own listing on a channel (never part of the market)', 'ritmo-revenue'); ?></label></p>
        <p><input type="hidden" name="competitor_fields[active]" value="0"><label class="ritmo-check"><input type="checkbox" name="competitor_fields[active]" value="1" <?php checked($ritmo_editing['active'] ?? true); ?>> <?php esc_html_e('Counts in the market', 'ritmo-revenue'); ?></label></p>
        <button type="submit" class="button button-primary"><?php esc_html_e('Save the competitor', 'ritmo-revenue'); ?></button>
    </form>
<?php endif; ?>
