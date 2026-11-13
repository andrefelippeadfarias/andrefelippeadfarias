<?php
/**
 * One report table: caption, column headers, the rows (the first cell heads its row), the totals
 * row when there is one, and the CSV download of the same table.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @var \Ritmo\Admin\Reports\ReportTable $ritmo_table */
$ritmo_table = $view['table'];
$ritmo_page = $view['page'];
$ritmo_currency = $view['currency'];
?>
<section class="ritmo-report-table" data-ritmo="report-table" data-table="<?php echo esc_attr($ritmo_table->id); ?>">
    <div class="ritmo-table-scroll">
        <table class="widefat striped ritmo-table">
            <caption><?php echo esc_html($ritmo_table->caption); ?></caption>
            <thead>
                <tr>
                    <?php foreach ($ritmo_table->columns as [$ritmo_label]) : ?>
                        <th scope="col"><?php echo esc_html($ritmo_label); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($ritmo_table->isEmpty()) : ?>
                    <tr><td colspan="<?php echo esc_attr((string) count($ritmo_table->columns)); ?>"><?php esc_html_e('Nothing to show for this period.', 'ritmo-revenue'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($ritmo_table->rows as $ritmo_row) : ?>
                    <tr>
                        <?php foreach ($ritmo_table->formatted($ritmo_row, $ritmo_currency) as $ritmo_i => $ritmo_cell) : ?>
                            <?php if ($ritmo_i === 0) : ?>
                                <th scope="row"><?php echo esc_html($ritmo_cell); ?></th>
                            <?php else : ?>
                                <td data-label="<?php echo esc_attr($ritmo_table->columns[$ritmo_i][0]); ?>"><?php echo esc_html($ritmo_cell); ?></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($ritmo_table->totals !== null) : ?>
                <tfoot>
                    <tr data-ritmo="totals">
                        <?php foreach ($ritmo_table->formatted($ritmo_table->totals, $ritmo_currency) as $ritmo_i => $ritmo_cell) : ?>
                            <?php if ($ritmo_i === 0) : ?>
                                <th scope="row"><?php echo esc_html($ritmo_table->totals[0] ?? ''); ?></th>
                            <?php else : ?>
                                <td data-label="<?php echo esc_attr($ritmo_table->columns[$ritmo_i][0]); ?>"><?php echo esc_html($ritmo_table->totals[$ritmo_i] === null ? '' : $ritmo_cell); ?></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-download">
        <?php wp_nonce_field('ritmo_export_report'); ?>
        <input type="hidden" name="action" value="ritmo_export_report">
        <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_page->property->id); ?>">
        <input type="hidden" name="tab" value="<?php echo esc_attr($view['tab']); ?>">
        <input type="hidden" name="month" value="<?php echo esc_attr($view['month']); ?>">
        <input type="hidden" name="table" value="<?php echo esc_attr($ritmo_table->id); ?>">
        <button type="submit" class="button" data-ritmo="download-csv">
            <?php
            /* translators: %s: the table's caption */
            echo esc_html(sprintf(__('Download CSV: %s', 'ritmo-revenue'), $ritmo_table->caption));
            ?>
        </button>
    </form>
</section>
