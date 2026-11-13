<?php
/**
 * The CSV downloads of the market: values per night, the prices, the competitors.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
$ritmo_property = $ritmo_page->property?->id ?? '';
$ritmo_kinds = [
    'ruler' => [__('Market values per night', 'ritmo-revenue'), true],
    'samples' => [__('Competitor prices', 'ritmo-revenue'), true],
    'competitors' => [__('Competitors', 'ritmo-revenue'), false],
];
?>
<p class="ritmo-intro"><?php esc_html_e('CSV files open in any spreadsheet. A text that starts like a formula is written with an apostrophe in front, so it never runs.', 'ritmo-revenue'); ?></p>
<div class="ritmo-grid">
    <?php foreach ($ritmo_kinds as $ritmo_kind => [$ritmo_title, $ritmo_dates]) : ?>
        <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card ritmo-form" data-ritmo="market-export">
            <?php wp_nonce_field('ritmo_market_export'); ?>
            <input type="hidden" name="action" value="ritmo_market_export">
            <input type="hidden" name="kind" value="<?php echo esc_attr($ritmo_kind); ?>">
            <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
            <input type="hidden" name="tab" value="export">
            <h2><?php echo esc_html($ritmo_title); ?></h2>
            <?php if ($ritmo_dates) : ?>
                <p><label for="ritmo-e-from-<?php echo esc_attr($ritmo_kind); ?>"><?php esc_html_e('From', 'ritmo-revenue'); ?></label><br><input type="date" id="ritmo-e-from-<?php echo esc_attr($ritmo_kind); ?>" name="from" value="<?php echo esc_attr($view['today']); ?>"></p>
                <p><label for="ritmo-e-to-<?php echo esc_attr($ritmo_kind); ?>"><?php esc_html_e('To', 'ritmo-revenue'); ?></label><br><input type="date" id="ritmo-e-to-<?php echo esc_attr($ritmo_kind); ?>" name="to" value="<?php echo esc_attr($view['until']); ?>"></p>
            <?php endif; ?>
            <button type="submit" class="button"><?php esc_html_e('Download CSV', 'ritmo-revenue'); ?></button>
        </form>
    <?php endforeach; ?>
</div>
