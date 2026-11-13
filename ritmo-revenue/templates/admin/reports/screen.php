<?php
/**
 * The Reports screen: the summary sentence, the tables of the tab with their CSV, and the forms
 * of the review and targets tabs.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\Reports\BarChart;
use Ritmo\Admin\Reports\ReportsText;
use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
?>
<div class="ritmo-reports" data-ritmo="reports">
<?php if ($view['no_property']) : ?>
    <div class="ritmo-empty" data-ritmo="empty"><p><?php esc_html_e('Create a property first: reports are kept per property.', 'ritmo-revenue'); ?></p></div>
<?php elseif ($view['tab'] === 'runs') : ?>
    <?php View::render('admin/reports/runs', $view); ?>
<?php else : ?>
    <?php if ($view['tab'] === 'month') : ?>
        <form method="get" class="ritmo-filters" data-ritmo="month-picker">
            <input type="hidden" name="page" value="<?php echo esc_attr($ritmo_page->slug); ?>">
            <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_page->property->id); ?>">
            <input type="hidden" name="tab" value="month">
            <label for="ritmo-r-month"><?php esc_html_e('Month', 'ritmo-revenue'); ?></label>
            <select id="ritmo-r-month" name="month">
                <?php foreach ($view['months'] as $ritmo_month) : ?>
                    <option value="<?php echo esc_attr($ritmo_month); ?>" <?php selected($ritmo_month, $view['month']); ?>><?php echo esc_html(ReportsText::month($ritmo_month)); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button"><?php esc_html_e('Show', 'ritmo-revenue'); ?></button>
        </form>
    <?php endif; ?>
    <?php if ($view['empty']) : ?>
        <div class="ritmo-empty" data-ritmo="empty">
            <p><strong><?php esc_html_e('Not enough data yet.', 'ritmo-revenue'); ?></strong></p>
            <p><?php esc_html_e('This report needs the bookings the runs read; it fills in after the first runs. Nothing is wrong.', 'ritmo-revenue'); ?></p>
        </div>
    <?php else : ?>
        <p class="ritmo-summary" data-ritmo="report-summary"><?php echo esc_html($view['summary']); ?></p>
        <?php if ($view['tab'] === 'review') : ?>
            <?php View::render('admin/reports/review', $view); ?>
        <?php endif; ?>
        <?php if ($view['chart'] !== null) : ?>
            <figure class="ritmo-chart">
                <svg class="ritmo-bars" aria-hidden="true" focusable="false" viewBox="<?php echo esc_attr('0 0 ' . BarChart::WIDTH . ' ' . $view['chart']['height']); ?>">
                    <?php foreach ($view['chart']['bars'] as $ritmo_bar) : ?>
                        <text class="ritmo-bars__label" x="0" y="<?php echo esc_attr((string) $ritmo_bar['textY']); ?>"><?php echo esc_html($ritmo_bar['label']); ?></text>
                        <rect class="ritmo-bars__bar" x="<?php echo esc_attr((string) $ritmo_bar['x']); ?>" y="<?php echo esc_attr((string) $ritmo_bar['y']); ?>" width="<?php echo esc_attr((string) $ritmo_bar['width']); ?>" height="22" rx="2"></rect>
                        <text class="ritmo-bars__value" x="<?php echo esc_attr((string) $ritmo_bar['valueX']); ?>" y="<?php echo esc_attr((string) $ritmo_bar['textY']); ?>"><?php echo esc_html($ritmo_bar['text']); ?></text>
                    <?php endforeach; ?>
                </svg>
            </figure>
        <?php endif; ?>
        <?php foreach ($view['tables'] as $ritmo_table) : ?>
            <?php View::render('admin/reports/table', ['table' => $ritmo_table] + $view); ?>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($view['tab'] === 'targets') : ?>
        <?php View::render('admin/reports/targets', $view); ?>
    <?php endif; ?>
<?php endif; ?>
</div>
