<?php
/**
 * Market by night: per night its kind and, per price series, the middle of the market and the
 * usual range in money, where the value came from and its age.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\Market\MarketText;

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
?>
<p class="ritmo-intro"><?php esc_html_e('What competitors charge per night, from the prices you import or type. The sales ladder and the pace read these values; they never change a price by themselves.', 'ritmo-revenue'); ?></p>
<?php if ($view['rows'] === [] || array_filter($view['rows'], static fn (array $r): bool => $r['cells'] !== [] || $r['anchor'] !== '') === []) : ?>
    <div class="ritmo-empty" data-ritmo="empty">
        <p><?php esc_html_e('No market value for these nights yet: add competitors and import their prices.', 'ritmo-revenue'); ?></p>
    </div>
<?php endif; ?>
<?php if ($view['gaps'] !== []) : ?>
    <div class="notice notice-info ritmo-callout" data-ritmo="market-gaps" role="status">
        <p><?php echo esc_html(sprintf(/* translators: 1: number of nights, 2: number of nights looked at */ _n('The sales ladder cannot price %1$d of the next %2$d nights: the market has no value it needs there. Import prices that cover these nights.', 'The sales ladder cannot price %1$d of the next %2$d nights: the market has no value it needs there. Import prices that cover these nights.', count($view['gaps']), 'ritmo-revenue'), count($view['gaps']), (int) $view['gap_nights'])); ?></p>
        <ul>
            <?php foreach (array_slice($view['gaps'], 0, 10) as $ritmo_gap) : ?>
                <li><?php echo esc_html($ritmo_gap['night'] . ': ' . $ritmo_gap['missing']); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($view['gaps']) > 10) : ?><p><?php echo esc_html(sprintf(/* translators: %d: number of nights not listed */ _n('and %d more night.', 'and %d more nights.', count($view['gaps']) - 10, 'ritmo-revenue'), count($view['gaps']) - 10)); ?></p><?php endif; ?>
    </div>
<?php endif; ?>
<div class="ritmo-table-scroll">
    <table class="widefat striped ritmo-table" data-ritmo="market-table">
        <caption><?php echo esc_html(sprintf(/* translators: 1: first night, 2: number of nights */ _n('Market from %1$s, %2$d night', 'Market from %1$s, %2$d nights', count($view['rows']), 'ritmo-revenue'), $view['from_text'], count($view['rows']))); ?></caption>
        <thead><tr>
            <th scope="col"><?php esc_html_e('Night', 'ritmo-revenue'); ?></th>
            <?php foreach ($view['series'] as $ritmo_name) : ?>
                <th scope="col"><?php echo esc_html(MarketText::series($ritmo_name)); ?></th>
            <?php endforeach; ?>
            <th scope="col"><?php esc_html_e('Market anchor (middle)', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('Updated', 'ritmo-revenue'); ?></th>
        </tr></thead>
        <tbody>
            <?php foreach ($view['rows'] as $ritmo_row) : ?>
                <tr>
                    <th scope="row" data-label="<?php esc_attr_e('Night', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['night']); ?><br><span class="ritmo-muted"><?php echo esc_html($ritmo_row['type']); ?></span></th>
                    <?php foreach ($view['series'] as $ritmo_name) : ?>
                        <?php $ritmo_cell = $ritmo_row['cells'][$ritmo_name] ?? null; ?>
                        <td data-label="<?php echo esc_attr(MarketText::series($ritmo_name)); ?>">
                            <?php if ($ritmo_cell === null) : ?>
                                <span class="ritmo-muted"><?php esc_html_e('no value', 'ritmo-revenue'); ?></span>
                            <?php else : ?>
                                <?php echo esc_html(sprintf(/* translators: %s: amount */ __('Middle %s', 'ritmo-revenue'), $ritmo_cell['median'])); ?>
                                <?php if ($ritmo_cell['range'] !== '') : ?><br><span class="ritmo-muted"><?php echo esc_html(sprintf(/* translators: %s: range of amounts */ __('usual range %s', 'ritmo-revenue'), $ritmo_cell['range'])); ?></span><?php endif; ?>
                                <br><span class="ritmo-muted"><?php echo esc_html(sprintf(/* translators: 1: where the value comes from, 2: number of competitors */ _n('%1$s, %2$d competitor', '%1$s, %2$d competitors', (int) $ritmo_cell['competitors'], 'ritmo-revenue'), $ritmo_cell['from'], (int) $ritmo_cell['competitors'])); ?></span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td data-label="<?php esc_attr_e('Market anchor (middle)', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['anchor'] === '' ? '—' : $ritmo_row['anchor']); ?></td>
                    <td data-label="<?php esc_attr_e('Updated', 'ritmo-revenue'); ?>">
                        <?php if ($ritmo_row['age'] === null) : ?>—<?php else : ?><?php echo esc_html(sprintf(/* translators: %d: number of days */ _n('captured %d day ago', 'captured %d days ago', (int) $ritmo_row['age'], 'ritmo-revenue'), (int) $ritmo_row['age'])); ?><?php endif; ?>
                        <?php if ($ritmo_row['old']) : ?> <span class="ritmo-badge ritmo-tone-attention"><?php esc_html_e('old', 'ritmo-revenue'); ?></span><?php endif; ?>
                        <?php if ($ritmo_row['small']) : ?> <span class="ritmo-badge ritmo-tone-neutral"><?php esc_html_e('small sample', 'ritmo-revenue'); ?></span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="ritmo-paging">
    <?php if ($view['previous'] !== null) : ?><a href="<?php echo esc_url($ritmo_page->url(['from' => $view['previous']])); ?>"><?php esc_html_e('Previous 30 nights', 'ritmo-revenue'); ?></a><?php endif; ?>
    <a href="<?php echo esc_url($ritmo_page->url(['from' => $view['next']])); ?>"><?php esc_html_e('Next 30 nights', 'ritmo-revenue'); ?></a>
</p>
