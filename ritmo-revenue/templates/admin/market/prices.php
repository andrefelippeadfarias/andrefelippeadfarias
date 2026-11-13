<?php
/**
 * The prices of competitors (one row per stay): filters, list, and a form to type one price.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\Components\Money;

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
$ritmo_property = $ritmo_page->property?->id ?? '';
$ritmo_names = array_column($view['competitors'], 'name', 'id');
$ritmo_filters = $view['filters'];
$ritmo_utc = new DateTimeZone('UTC');
?>
<form method="get" class="ritmo-filters" data-ritmo="price-filters">
    <input type="hidden" name="page" value="<?php echo esc_attr($ritmo_page->slug); ?>">
    <input type="hidden" name="tab" value="prices">
    <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
    <label for="ritmo-p-competitor"><?php esc_html_e('Competitor', 'ritmo-revenue'); ?></label>
    <select id="ritmo-p-competitor" name="competitor">
        <option value=""><?php esc_html_e('All', 'ritmo-revenue'); ?></option>
        <?php foreach ($view['competitors'] as $ritmo_c) : ?>
            <option value="<?php echo esc_attr((string) $ritmo_c['id']); ?>" <?php selected($ritmo_filters['competitor'] ?? 0, $ritmo_c['id']); ?>><?php echo esc_html($ritmo_c['name']); ?></option>
        <?php endforeach; ?>
    </select>
    <label for="ritmo-p-from"><?php esc_html_e('Check-in from', 'ritmo-revenue'); ?></label>
    <input type="date" id="ritmo-p-from" name="night_from" value="<?php echo esc_attr(isset($ritmo_filters['from']) ? $ritmo_filters['from']->format('Y-m-d') : ''); ?>">
    <label for="ritmo-p-to"><?php esc_html_e('to', 'ritmo-revenue'); ?></label>
    <input type="date" id="ritmo-p-to" name="night_to" value="<?php echo esc_attr(isset($ritmo_filters['to']) ? $ritmo_filters['to']->format('Y-m-d') : ''); ?>">
    <label for="ritmo-p-source"><?php esc_html_e('Source', 'ritmo-revenue'); ?></label>
    <select id="ritmo-p-source" name="source">
        <option value=""><?php esc_html_e('All', 'ritmo-revenue'); ?></option>
        <option value="csv" <?php selected($ritmo_filters['source'] ?? '', 'csv'); ?>><?php esc_html_e('Imported file', 'ritmo-revenue'); ?></option>
        <option value="manual" <?php selected($ritmo_filters['source'] ?? '', 'manual'); ?>><?php esc_html_e('Typed', 'ritmo-revenue'); ?></option>
        <option value="booking" <?php selected($ritmo_filters['source'] ?? '', 'booking'); ?>><?php esc_html_e('Search on a channel', 'ritmo-revenue'); ?></option>
    </select>
    <button type="submit" class="button"><?php esc_html_e('Apply', 'ritmo-revenue'); ?></button>
    <a href="<?php echo esc_url($ritmo_page->url()); ?>"><?php esc_html_e('Clear', 'ritmo-revenue'); ?></a>
</form>

<?php if ($view['prices']['rows'] === []) : ?>
    <div class="ritmo-empty" data-ritmo="empty"><p><?php esc_html_e('No price matches. Import a price file or type a price below.', 'ritmo-revenue'); ?></p></div>
<?php else : ?>
    <table class="widefat striped ritmo-table">
        <caption><?php echo esc_html(sprintf(/* translators: 1: first row shown, 2: last row shown, 3: total */ __('Prices %1$d–%2$d of %3$d', 'ritmo-revenue'), ($view['page_number'] - 1) * 100 + 1, ($view['page_number'] - 1) * 100 + count($view['prices']['rows']), $view['prices']['total'])); ?></caption>
        <thead><tr>
            <th scope="col"><?php esc_html_e('Competitor', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('Stay', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('Room', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('Per night', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('Captured', 'ritmo-revenue'); ?></th>
        </tr></thead>
        <tbody>
            <?php foreach ($view['prices']['rows'] as $ritmo_row) : ?>
                <tr>
                    <th scope="row" data-label="<?php esc_attr_e('Competitor', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_names[$ritmo_row['competitor_id']] ?? __('Pasted price without a competitor', 'ritmo-revenue')); ?></th>
                    <td data-label="<?php esc_attr_e('Stay', 'ritmo-revenue'); ?>"><?php echo esc_html(sprintf(/* translators: 1: check-in date, 2: number of nights */ _n('%1$s, %2$d night', '%1$s, %2$d nights', (int) $ritmo_row['nights'], 'ritmo-revenue'), wp_date('D j M Y', $ritmo_row['check_in']->getTimestamp(), $ritmo_utc), (int) $ritmo_row['nights'])); ?></td>
                    <td data-label="<?php esc_attr_e('Room', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['basis'] === 'comparable' ? __('Comparable room', 'ritmo-revenue') : __('Cheapest room', 'ritmo-revenue')); ?><?php if (!$ritmo_row['available']) : ?> <span class="ritmo-badge ritmo-tone-neutral"><?php esc_html_e('sold out', 'ritmo-revenue'); ?></span><?php endif; ?></td>
                    <td data-label="<?php esc_attr_e('Per night', 'ritmo-revenue'); ?>"><?php echo esc_html(Money::format($ritmo_row['price'], $ritmo_page->currency())); ?><?php if ($ritmo_row['total'] !== null) : ?><br><span class="ritmo-muted"><?php echo esc_html(sprintf(/* translators: %s: amount */ __('stay total %s', 'ritmo-revenue'), Money::format($ritmo_row['total'], $ritmo_page->currency()))); ?></span><?php endif; ?></td>
                    <td data-label="<?php esc_attr_e('Captured', 'ritmo-revenue'); ?>"><?php echo esc_html(wp_date(get_option('date_format'), $ritmo_row['captured_at']->getTimestamp())); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="ritmo-paging">
        <?php if ($view['page_number'] > 1) : ?><a href="<?php echo esc_url($ritmo_page->url(['paged' => $view['page_number'] - 1] + array_map(static fn ($v): string => $v instanceof DateTimeInterface ? $v->format('Y-m-d') : (string) $v, $ritmo_filters))); ?>"><?php esc_html_e('Previous', 'ritmo-revenue'); ?></a><?php endif; ?>
        <?php if ($view['page_number'] * 100 < $view['prices']['total']) : ?><a href="<?php echo esc_url($ritmo_page->url(['paged' => $view['page_number'] + 1] + array_map(static fn ($v): string => $v instanceof DateTimeInterface ? $v->format('Y-m-d') : (string) $v, $ritmo_filters))); ?>"><?php esc_html_e('Next', 'ritmo-revenue'); ?></a><?php endif; ?>
    </p>
<?php endif; ?>

<?php if ($view['may_edit'] && $view['competitors'] !== []) : ?>
    <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card ritmo-form">
        <?php wp_nonce_field('ritmo_sample_add'); ?>
        <input type="hidden" name="action" value="ritmo_sample_add">
        <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
        <input type="hidden" name="tab" value="prices">
        <h2><?php esc_html_e('Type a price', 'ritmo-revenue'); ?></h2>
        <p><label for="ritmo-s-competitor"><?php esc_html_e('Competitor', 'ritmo-revenue'); ?></label><br>
            <select id="ritmo-s-competitor" name="sample_competitor">
                <?php foreach ($view['competitors'] as $ritmo_c) : ?><?php if ($ritmo_c['active']) : ?><option value="<?php echo esc_attr($ritmo_c['code']); ?>"><?php echo esc_html($ritmo_c['name']); ?></option><?php endif; ?><?php endforeach; ?>
            </select></p>
        <p><label for="ritmo-s-in"><?php esc_html_e('Check-in', 'ritmo-revenue'); ?></label><br><input type="date" id="ritmo-s-in" name="check_in" required></p>
        <p><label for="ritmo-s-out"><?php esc_html_e('Check-out', 'ritmo-revenue'); ?></label><br><input type="date" id="ritmo-s-out" name="check_out" required></p>
        <p><label for="ritmo-s-price"><?php esc_html_e('Price', 'ritmo-revenue'); ?></label><br><input type="text" inputmode="decimal" id="ritmo-s-price" name="price" required> <?php echo esc_html($ritmo_page->currency()); ?></p>
        <p><label for="ritmo-s-basis"><?php esc_html_e('The price is', 'ritmo-revenue'); ?></label><br>
            <select id="ritmo-s-basis" name="price_basis">
                <option value="per_night"><?php esc_html_e('per night', 'ritmo-revenue'); ?></option>
                <option value="total"><?php esc_html_e('the total of the stay', 'ritmo-revenue'); ?></option>
            </select></p>
        <p><label for="ritmo-s-room"><?php esc_html_e('Room', 'ritmo-revenue'); ?></label><br>
            <select id="ritmo-s-room" name="room">
                <option value="cheapest"><?php esc_html_e('Cheapest room', 'ritmo-revenue'); ?></option>
                <option value="comparable"><?php esc_html_e('Comparable room', 'ritmo-revenue'); ?></option>
            </select></p>
        <p><input type="hidden" name="available" value="0"><label class="ritmo-check"><input type="checkbox" name="available" value="1" checked> <?php esc_html_e('Available at that price', 'ritmo-revenue'); ?></label></p>
        <button type="submit" class="button button-primary"><?php esc_html_e('Record the price', 'ritmo-revenue'); ?></button>
    </form>
<?php endif; ?>
