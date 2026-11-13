<?php
/**
 * The import of a price file in two steps: upload and preview (nothing stored), then import or
 * cancel. The file itself is never kept.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\Components\Money;
use Ritmo\Admin\Market\MarketText;
use Ritmo\Domain\Market\Csv\CsvColumns;
use Ritmo\Domain\Market\Csv\MarketCsvProblem;

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
$ritmo_property = $ritmo_page->property?->id ?? '';
$ritmo_preview = $view['preview'];
$ritmo_utc = new DateTimeZone('UTC');
$ritmo_codes = array_column($view['competitors'], 'name', 'id');
?>
<?php if ($ritmo_preview === null) : ?>
    <form method="post" action="<?php echo esc_url($view['post_url']); ?>" enctype="multipart/form-data" class="ritmo-card ritmo-form" data-ritmo="csv-upload">
        <?php wp_nonce_field('ritmo_market_csv_preview'); ?>
        <input type="hidden" name="action" value="ritmo_market_csv_preview">
        <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
        <input type="hidden" name="tab" value="import">
        <h2><?php esc_html_e('Import a price file', 'ritmo-revenue'); ?></h2>
        <p><label for="ritmo-prices-file"><?php echo esc_html(sprintf(/* translators: %s: size limit */ __('Price file (.csv, up to %s)', 'ritmo-revenue'), size_format($view['max_bytes']))); ?></label><br>
            <input type="file" id="ritmo-prices-file" name="prices_file" accept=".csv,text/csv" required></p>
        <p class="ritmo-help"><?php echo esc_html(sprintf(/* translators: 1: required columns, 2: optional columns */ __('One line per stay. Required columns: %1$s. Optional: %2$s. Comma or semicolon separated, UTF-8.', 'ritmo-revenue'), implode(', ', CsvColumns::REQUIRED), implode(', ', CsvColumns::OPTIONAL))); ?></p>
        <p class="ritmo-help"><?php esc_html_e('Nothing is imported at this step: you see what the file holds first.', 'ritmo-revenue'); ?></p>
        <button type="submit" class="button button-primary"><?php esc_html_e('Preview', 'ritmo-revenue'); ?></button>
    </form>
    <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-inline">
        <?php wp_nonce_field('ritmo_market_template'); ?>
        <input type="hidden" name="action" value="ritmo_market_template">
        <button type="submit" class="button-link"><?php esc_html_e('Download an example file', 'ritmo-revenue'); ?></button>
    </form>
<?php else : ?>
    <section class="ritmo-card">
        <h2><?php esc_html_e('What this file would import', 'ritmo-revenue'); ?></h2>
        <p data-ritmo="csv-preview-summary"><?php echo esc_html(sprintf(
            /* translators: 1: valid rows, 2: nights, 3: competitors, 4: lines ignored */
            __('%1$d valid rows for %2$d nights and %3$d competitors; %4$d lines ignored.', 'ritmo-revenue'),
            $ritmo_preview->valid,
            $ritmo_preview->nights,
            $ritmo_preview->competitors,
            $ritmo_preview->invalidLines
        )); ?></p>
        <?php if ($ritmo_preview->unknownCompetitors !== [] && $view['may_edit']) : ?>
            <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-callout">
                <?php wp_nonce_field('ritmo_competitors_create'); ?>
                <input type="hidden" name="action" value="ritmo_competitors_create">
                <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
                <input type="hidden" name="tab" value="import">
                <p><?php echo esc_html(sprintf(/* translators: %s: names */ __('The file names competitors this property does not have: %s.', 'ritmo-revenue'), implode(', ', $ritmo_preview->unknownCompetitors))); ?></p>
                <?php foreach ($ritmo_preview->unknownCompetitors as $ritmo_name) : ?><input type="hidden" name="names[]" value="<?php echo esc_attr($ritmo_name); ?>"><?php endforeach; ?>
                <button type="submit" class="button"><?php esc_html_e('Create them', 'ritmo-revenue'); ?></button>
            </form>
        <?php endif; ?>
        <?php $ritmo_errors = array_values(array_filter($ritmo_preview->problems, static fn (MarketCsvProblem $p): bool => $p->kind === MarketCsvProblem::ERROR)); ?>
        <?php $ritmo_warnings = array_values(array_filter($ritmo_preview->problems, static fn (MarketCsvProblem $p): bool => $p->kind !== MarketCsvProblem::ERROR)); ?>
        <?php if ($ritmo_errors !== []) : ?>
            <h3><?php esc_html_e('Lines ignored', 'ritmo-revenue'); ?></h3>
            <ul data-ritmo="csv-preview-invalid">
                <?php foreach ($ritmo_errors as $ritmo_problem) : ?><li><?php echo esc_html(MarketText::problem($ritmo_problem)); ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($ritmo_warnings !== []) : ?>
            <h3><?php esc_html_e('Notes', 'ritmo-revenue'); ?></h3>
            <ul>
                <?php foreach ($ritmo_warnings as $ritmo_problem) : ?><li><?php echo esc_html(MarketText::problem($ritmo_problem)); ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($ritmo_preview->moreProblems > 0) : ?><p><?php echo esc_html(sprintf(/* translators: %d: number of problems not listed */ __('and %d more.', 'ritmo-revenue'), $ritmo_preview->moreProblems)); ?></p><?php endif; ?>
        <?php if ($ritmo_preview->firstRows !== []) : ?>
            <table class="widefat striped ritmo-table" data-ritmo="csv-preview-valid">
                <caption><?php echo esc_html(sprintf(/* translators: %d: number of rows shown */ __('The first %d valid rows', 'ritmo-revenue'), count($ritmo_preview->firstRows))); ?></caption>
                <thead><tr>
                    <th scope="col"><?php esc_html_e('Line', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Competitor', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Stay', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Room', 'ritmo-revenue'); ?></th>
                    <th scope="col"><?php esc_html_e('Per night', 'ritmo-revenue'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($ritmo_preview->firstRows as $ritmo_row) : ?>
                        <tr>
                            <th scope="row" data-label="<?php esc_attr_e('Line', 'ritmo-revenue'); ?>"><?php echo esc_html((string) $ritmo_row->line); ?></th>
                            <td data-label="<?php esc_attr_e('Competitor', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_codes[$ritmo_row->competitorId] ?? ''); ?></td>
                            <td data-label="<?php esc_attr_e('Stay', 'ritmo-revenue'); ?>"><?php echo esc_html(sprintf(/* translators: 1: check-in date, 2: number of nights */ _n('%1$s, %2$d night', '%1$s, %2$d nights', $ritmo_row->nights, 'ritmo-revenue'), wp_date('D j M Y', $ritmo_row->checkIn->getTimestamp(), $ritmo_utc), $ritmo_row->nights)); ?></td>
                            <td data-label="<?php esc_attr_e('Room', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row->basis->value === 'comparable' ? __('Comparable room', 'ritmo-revenue') : __('Cheapest room', 'ritmo-revenue')); ?></td>
                            <td data-label="<?php esc_attr_e('Per night', 'ritmo-revenue'); ?>"><?php echo esc_html(Money::format($ritmo_row->price, $ritmo_page->currency())); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <div class="ritmo-actions">
            <?php if ($ritmo_preview->valid > 0) : ?>
                <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-inline">
                    <?php wp_nonce_field('ritmo_market_csv_import'); ?>
                    <input type="hidden" name="action" value="ritmo_market_csv_import">
                    <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
                    <input type="hidden" name="tab" value="import">
                    <input type="hidden" name="token" value="<?php echo esc_attr($ritmo_preview->token); ?>">
                    <button type="submit" class="button button-primary" data-ritmo="csv-import"><?php echo esc_html(sprintf(/* translators: %d: number of rows */ _n('Import %d row', 'Import %d rows', $ritmo_preview->valid, 'ritmo-revenue'), $ritmo_preview->valid)); ?></button>
                </form>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-inline">
                <?php wp_nonce_field('ritmo_market_csv_cancel'); ?>
                <input type="hidden" name="action" value="ritmo_market_csv_cancel">
                <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
                <input type="hidden" name="tab" value="import">
                <input type="hidden" name="token" value="<?php echo esc_attr($ritmo_preview->token); ?>">
                <button type="submit" class="button"><?php esc_html_e('Cancel', 'ritmo-revenue'); ?></button>
            </form>
        </div>
    </section>
<?php endif; ?>

<section class="ritmo-card">
    <h2><?php esc_html_e('Last imports', 'ritmo-revenue'); ?></h2>
    <?php if ($view['imports'] === []) : ?>
        <p><?php esc_html_e('No file imported yet.', 'ritmo-revenue'); ?></p>
    <?php else : ?>
        <table class="widefat striped ritmo-table">
            <caption><?php esc_html_e('The 10 latest price files', 'ritmo-revenue'); ?></caption>
            <thead><tr><th scope="col"><?php esc_html_e('File', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('When', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Rows', 'ritmo-revenue'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($view['imports'] as $ritmo_import) : ?>
                    <tr>
                        <th scope="row" data-label="<?php esc_attr_e('File', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_import['file_name']); ?></th>
                        <td data-label="<?php esc_attr_e('When', 'ritmo-revenue'); ?>"><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ritmo_import['created_at']->getTimestamp())); ?></td>
                        <td data-label="<?php esc_attr_e('Rows', 'ritmo-revenue'); ?>"><?php echo esc_html(sprintf(/* translators: 1: rows imported, 2: rows updated, 3: lines ignored */ __('%1$d new, %2$d updated, %3$d ignored', 'ritmo-revenue'), $ritmo_import['rows_imported'], $ritmo_import['rows_replaced'], $ritmo_import['rows_invalid'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
