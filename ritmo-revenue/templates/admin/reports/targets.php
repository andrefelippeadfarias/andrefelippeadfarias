<?php
/**
 * The owner's monthly targets: one form per month (labels on every field, money with the
 * currency). Read only without ritmo_manage_rules.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\Components\Money;
use Ritmo\Admin\Reports\ReportsText;

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
$ritmo_symbol = Money::symbol($view['currency']);
$ritmo_labels = [
    /* translators: %s: currency symbol */
    'minimum' => sprintf(__('Minimum revenue (%s)', 'ritmo-revenue'), $ritmo_symbol),
    /* translators: %s: currency symbol */
    'realistic' => sprintf(__('Realistic revenue (%s)', 'ritmo-revenue'), $ritmo_symbol),
    'market_occupancy_ly' => __('Market occupancy last year (%)', 'ritmo-revenue'),
    /* translators: %s: currency symbol */
    'market_adr_ly' => sprintf(__('Market daily rate last year (%s)', 'ritmo-revenue'), $ritmo_symbol),
    'occupancy_cap' => __('Occupancy cap (1 = none)', 'ritmo-revenue'),
    /* translators: %s: currency symbol */
    'adjust_floor' => sprintf(__('Adjustment, floor scenario (%s)', 'ritmo-revenue'), $ritmo_symbol),
    /* translators: %s: currency symbol */
    'adjust_base' => sprintf(__('Adjustment, base scenario (%s)', 'ritmo-revenue'), $ritmo_symbol),
    /* translators: %s: currency symbol */
    'adjust_goal' => sprintf(__('Adjustment, goal scenario (%s)', 'ritmo-revenue'), $ritmo_symbol),
    'market_sold_percent' => __('Market sold now (%)', 'ritmo-revenue'),
    'market_last_year_percent' => __('Market sold last year (%)', 'ritmo-revenue'),
];
?>
<h2><?php esc_html_e('Monthly targets', 'ritmo-revenue'); ?></h2>
<p class="ritmo-intro"><?php esc_html_e('Targets only change what the reports compare with. They never change a price.', 'ritmo-revenue'); ?></p>
<?php foreach ($view['target_months'] as $ritmo_month) : ?>
    <?php $ritmo_row = $view['target_rows'][$ritmo_month] ?? null; ?>
    <details class="ritmo-target" data-ritmo="target-month" data-month="<?php echo esc_attr($ritmo_month); ?>">
        <summary><?php echo esc_html(ReportsText::month($ritmo_month)); ?></summary>
        <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-form ritmo-target-form">
            <?php wp_nonce_field('ritmo_targets_save'); ?>
            <input type="hidden" name="action" value="ritmo_targets_save">
            <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_page->property->id); ?>">
            <input type="hidden" name="month" value="<?php echo esc_attr($ritmo_month); ?>">
            <?php foreach ($view['target_fields'] as $ritmo_field) : ?>
                <?php $ritmo_id = 'ritmo-t-' . $ritmo_month . '-' . $ritmo_field; ?>
                <p>
                    <label for="<?php echo esc_attr($ritmo_id); ?>"><?php echo esc_html($ritmo_labels[$ritmo_field]); ?></label><br>
                    <input type="text" inputmode="decimal" id="<?php echo esc_attr($ritmo_id); ?>" name="<?php echo esc_attr('target_' . $ritmo_field); ?>" value="<?php echo esc_attr($ritmo_row?->value($ritmo_field) === null ? '' : (string) $ritmo_row->value($ritmo_field)); ?>" <?php disabled(!$view['may_edit']); ?>>
                </p>
            <?php endforeach; ?>
            <?php if ($view['may_edit']) : ?>
                <button type="submit" class="button button-primary"><?php esc_html_e('Save the targets of this month', 'ritmo-revenue'); ?></button>
            <?php endif; ?>
        </form>
    </details>
<?php endforeach; ?>
