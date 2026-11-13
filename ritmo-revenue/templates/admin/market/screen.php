<?php
/**
 * The Market screen: the freshness banner, then the tab (market by night, competitors, prices,
 * import, export).
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
?>
<?php if ($view['no_property']) : ?>
    <div class="ritmo-empty" data-ritmo="empty"><p><?php esc_html_e('Create a property first: competitors and their prices are kept per property.', 'ritmo-revenue'); ?></p></div>
<?php else : ?>
    <?php if ($view['freshness']['stale']) : ?>
        <div class="notice notice-warning ritmo-callout" data-ritmo="market-stale" role="status">
            <p>
                <?php if ($view['freshness']['age_days'] === null) : ?>
                    <?php esc_html_e('No market price covers the coming nights yet. Import or type prices so the sales ladder and the pace can read the market.', 'ritmo-revenue'); ?>
                <?php else : ?>
                    <?php echo esc_html(sprintf(/* translators: %d: number of days */ _n('Market prices were last updated %d day ago. Import or type this week\'s prices.', 'Market prices were last updated %d days ago. Import or type this week\'s prices.', (int) $view['freshness']['age_days'], 'ritmo-revenue'), (int) $view['freshness']['age_days'])); ?>
                <?php endif; ?>
                <?php if ($view['may_edit']) : ?><a href="<?php echo esc_url($ritmo_page->url(['tab' => 'import'])); ?>"><?php esc_html_e('Import a price file', 'ritmo-revenue'); ?></a><?php endif; ?>
            </p>
        </div>
    <?php endif; ?>
    <?php View::render('admin/market/' . $ritmo_page->tab, $view); ?>
<?php endif; ?>
