<?php
/**
 * The calendar tab: weekend nights, three months with each night's marks, holiday periods in the
 * order that decides overlaps, seasons and packages, and the import of national holidays.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\Editors\ValueText;
use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
$ritmo_property = $ritmo_page->property?->id ?? '';
View::render('admin/editors/draft-bar', $view);
?>
<?php if ($view['weekend'] !== null) : ?>
    <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card ritmo-form" data-ritmo="weekend-days">
        <?php wp_nonce_field('ritmo_rules_draft_update'); ?>
        <input type="hidden" name="action" value="ritmo_rules_draft_update">
        <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
        <input type="hidden" name="tab" value="calendar">
        <input type="hidden" name="scope" value="calendar">
        <?php View::render('admin/editors/field', ['node' => $view['weekend']]); ?>
        <p class="ritmo-help"><?php esc_html_e('A night is named by its check-in day: the Friday night is the night from Friday to Saturday.', 'ritmo-revenue'); ?></p>
        <?php if (!$view['read_only']) : ?><button type="submit" class="button"><?php esc_html_e('Keep in my draft', 'ritmo-revenue'); ?></button><?php endif; ?>
    </form>
<?php endif; ?>

<section class="ritmo-card">
    <h2><?php esc_html_e('Calendar', 'ritmo-revenue'); ?></h2>
    <p class="ritmo-legend"><?php esc_html_e('Each night shows in words what applies to it: weekend night, the holiday period, the season and its minimum stay, the package.', 'ritmo-revenue'); ?></p>
    <p class="ritmo-paging">
        <a href="<?php echo esc_url($ritmo_page->url(['month' => $view['previous']])); ?>"><?php esc_html_e('Previous 3 months', 'ritmo-revenue'); ?></a>
        <a href="<?php echo esc_url($ritmo_page->url(['month' => $view['next']])); ?>"><?php esc_html_e('Next 3 months', 'ritmo-revenue'); ?></a>
    </p>
    <div class="ritmo-months">
        <?php foreach ($view['months'] as $ritmo_month) : ?>
            <div class="ritmo-table-scroll">
                <table class="ritmo-month" data-ritmo="month-<?php echo esc_attr($ritmo_month['key']); ?>">
                    <caption><?php echo esc_html($ritmo_month['caption']); ?></caption>
                    <thead><tr>
                        <?php for ($ritmo_day = 0; $ritmo_day < 7; ++$ritmo_day) : ?>
                            <th scope="col"><abbr title="<?php echo esc_attr(ValueText::weekday($ritmo_day)); ?>"><?php echo esc_html(ValueText::weekdayShort($ritmo_day)); ?></abbr></th>
                        <?php endfor; ?>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($ritmo_month['weeks'] as $ritmo_week) : ?>
                            <tr>
                                <?php foreach ($ritmo_week as $ritmo_cell) : ?>
                                    <?php if ($ritmo_cell === null) : ?>
                                        <td class="ritmo-blank"></td>
                                    <?php else : ?>
                                        <td class="<?php echo esc_attr(implode(' ', array_map(static fn (string $k): string => 'ritmo-' . $k, $ritmo_cell['kinds']))); ?>">
                                            <span class="ritmo-day"><?php echo esc_html((string) $ritmo_cell['day']); ?></span>
                                            <?php foreach ($ritmo_cell['marks'] as $ritmo_mark) : ?>
                                                <span class="ritmo-mark"><?php echo esc_html($ritmo_mark); ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="ritmo-card">
    <h2><?php esc_html_e('Holidays and special periods', 'ritmo-revenue'); ?></h2>
    <?php if ($view['holidays'] === []) : ?>
        <p data-ritmo="empty"><?php esc_html_e('No holiday or special period: every night is a weekday or weekend night.', 'ritmo-revenue'); ?></p>
    <?php else : ?>
        <p class="ritmo-help"><?php esc_html_e('When periods overlap, the first one in the list wins.', 'ritmo-revenue'); ?></p>
        <table class="widefat striped ritmo-table">
            <caption><?php esc_html_e('Periods, in the order that decides overlaps', 'ritmo-revenue'); ?></caption>
            <thead><tr>
                <th scope="col"><?php esc_html_e('Name', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Nights', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Priced as', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Own minimum stay', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Own floor', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Actions', 'ritmo-revenue'); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($view['holidays'] as $ritmo_row) : ?>
                    <tr data-ritmo="holiday-row">
                        <th scope="row" data-label="<?php esc_attr_e('Name', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['name']); ?>
                            <?php if ($ritmo_row['overlap'] !== null) : ?><br><span class="ritmo-muted"><?php echo esc_html(sprintf(/* translators: %s: the earlier period */ __('Overlaps %s: the earlier one wins on the shared nights.', 'ritmo-revenue'), $ritmo_row['overlap'])); ?></span><?php endif; ?>
                        </th>
                        <td data-label="<?php esc_attr_e('Nights', 'ritmo-revenue'); ?>"><?php echo esc_html(sprintf(/* translators: 1: range of nights, 2: number of nights */ _n('%1$s · %2$d night', '%1$s · %2$d nights', (int) $ritmo_row['nights'], 'ritmo-revenue'), $ritmo_row['range'], (int) $ritmo_row['nights'])); ?></td>
                        <td data-label="<?php esc_attr_e('Priced as', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['type']); ?></td>
                        <td data-label="<?php esc_attr_e('Own minimum stay', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['min_stay'] === null ? __('none', 'ritmo-revenue') : (string) $ritmo_row['min_stay']); ?></td>
                        <td data-label="<?php esc_attr_e('Own floor', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['floor'] === '' ? __('none', 'ritmo-revenue') : $ritmo_row['floor']); ?></td>
                        <td data-label="<?php esc_attr_e('Actions', 'ritmo-revenue'); ?>">
                            <?php if (!$view['read_only']) : ?>
                                <?php foreach (['up' => __('Move up', 'ritmo-revenue'), 'down' => __('Move down', 'ritmo-revenue'), 'delete' => __('Delete', 'ritmo-revenue')] as $ritmo_op => $ritmo_text) : ?>
                                    <?php if (($ritmo_op === 'up' && $ritmo_row['index'] === 0) || ($ritmo_op === 'down' && $ritmo_row['last'])) : ?><?php continue; ?><?php endif; ?>
                                    <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-inline">
                                        <?php wp_nonce_field('ritmo_calendar_stage'); ?>
                                        <input type="hidden" name="action" value="ritmo_calendar_stage">
                                        <input type="hidden" name="op" value="<?php echo esc_attr($ritmo_op); ?>">
                                        <input type="hidden" name="index" value="<?php echo esc_attr((string) $ritmo_row['index']); ?>">
                                        <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
                                        <input type="hidden" name="tab" value="calendar">
                                        <button type="submit" class="button"><?php echo esc_html($ritmo_text); ?></button>
                                    </form>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php if (!$view['read_only']) : ?>
        <details class="ritmo-item">
            <summary><?php esc_html_e('Add a period', 'ritmo-revenue'); ?></summary>
            <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-form">
                <?php wp_nonce_field('ritmo_calendar_stage'); ?>
                <input type="hidden" name="action" value="ritmo_calendar_stage">
                <input type="hidden" name="op" value="add">
                <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
                <input type="hidden" name="tab" value="calendar">
                <p><label for="ritmo-period-name"><?php esc_html_e('Name', 'ritmo-revenue'); ?></label><br><input type="text" id="ritmo-period-name" name="period[name]" maxlength="120" required></p>
                <p><label for="ritmo-period-start"><?php esc_html_e('First night', 'ritmo-revenue'); ?></label><br><input type="date" id="ritmo-period-start" name="period[start]" required></p>
                <p><label for="ritmo-period-end"><?php esc_html_e('Last night (not the departure day)', 'ritmo-revenue'); ?></label><br><input type="date" id="ritmo-period-end" name="period[end]" required></p>
                <p><label for="ritmo-period-type"><?php esc_html_e('Priced as', 'ritmo-revenue'); ?></label><br>
                    <select id="ritmo-period-type" name="period[night_type]">
                        <option value="holiday"><?php esc_html_e('Holiday nights', 'ritmo-revenue'); ?></option>
                        <option value="weekend"><?php esc_html_e('Weekend nights', 'ritmo-revenue'); ?></option>
                        <option value="weekday"><?php esc_html_e('Weekday nights', 'ritmo-revenue'); ?></option>
                    </select></p>
                <p><label for="ritmo-period-stay"><?php esc_html_e('Own minimum stay (optional)', 'ritmo-revenue'); ?></label><br><input type="number" id="ritmo-period-stay" name="period[min_stay]" min="1" max="30" step="1"> <?php esc_html_e('nights', 'ritmo-revenue'); ?></p>
                <p><label for="ritmo-period-floor"><?php esc_html_e('Own floor (optional)', 'ritmo-revenue'); ?></label><br><input type="number" id="ritmo-period-floor" name="period[floor]" min="0" step="0.01" inputmode="decimal"> <?php echo esc_html($ritmo_page->currency()); ?></p>
                <button type="submit" class="button button-primary"><?php esc_html_e('Add to my draft', 'ritmo-revenue'); ?></button>
            </form>
        </details>
    <?php endif; ?>
</section>

<?php if ($view['seasons_and_packages'] !== []) : ?>
    <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card ritmo-form">
        <?php wp_nonce_field('ritmo_rules_draft_update'); ?>
        <input type="hidden" name="action" value="ritmo_rules_draft_update">
        <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
        <input type="hidden" name="tab" value="calendar">
        <input type="hidden" name="scope" value="calendar">
        <h2><?php esc_html_e('Seasons and packages', 'ritmo-revenue'); ?></h2>
        <?php foreach ($view['seasons_and_packages'] as $ritmo_node) : ?>
            <?php View::render('admin/editors/field', ['node' => $ritmo_node]); ?>
        <?php endforeach; ?>
        <?php if (!$view['read_only']) : ?><button type="submit" class="button"><?php esc_html_e('Keep in my draft', 'ritmo-revenue'); ?></button><?php endif; ?>
    </form>
<?php endif; ?>

<section class="ritmo-card" data-ritmo="holiday-import">
    <h2><?php esc_html_e('Import national holidays', 'ritmo-revenue'); ?></h2>
    <?php if ($view['load_failed']) : ?>
        <p class="ritmo-field-error" data-ritmo="error-ref"><?php esc_html_e('The holiday lists of the plugin could not be read. Nothing was changed.', 'ritmo-revenue'); ?></p>
    <?php else : ?>
        <p class="ritmo-help"><?php esc_html_e('From the lists shipped with the plugin; nothing is downloaded. The proposed nights go from the evening before the first day off to the night before the last one.', 'ritmo-revenue'); ?></p>
        <form method="get" class="ritmo-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr($ritmo_page->slug); ?>">
            <input type="hidden" name="tab" value="calendar">
            <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
            <input type="hidden" name="preview" value="1">
            <label for="ritmo-country"><?php esc_html_e('Country', 'ritmo-revenue'); ?></label>
            <select id="ritmo-country" name="country">
                <?php foreach ($view['countries'] as $ritmo_country) : ?>
                    <option value="<?php echo esc_attr($ritmo_country['code']); ?>" <?php selected($ritmo_country['code'], $view['import']['country'] ?? ''); ?>><?php echo esc_html($ritmo_country['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <fieldset class="ritmo-inline-fieldset">
                <legend><?php esc_html_e('Years', 'ritmo-revenue'); ?></legend>
                <?php foreach ($view['years'] as $ritmo_year) : ?>
                    <label class="ritmo-check"><input type="checkbox" name="years[]" value="<?php echo esc_attr((string) $ritmo_year); ?>" <?php checked(in_array($ritmo_year, $view['import']['years'] ?? [$view['years'][0]], true)); ?>> <?php echo esc_html((string) $ritmo_year); ?></label>
                <?php endforeach; ?>
            </fieldset>
            <label class="ritmo-check"><input type="checkbox" name="bridges" value="1" <?php checked($view['import']['bridges'] ?? true); ?>> <?php esc_html_e('Include bridge days', 'ritmo-revenue'); ?></label>
            <button type="submit" class="button"><?php esc_html_e('Preview', 'ritmo-revenue'); ?></button>
        </form>
        <?php if ($view['import'] !== null) : ?>
            <?php if ($view['import']['error']) : ?>
                <p class="ritmo-field-error"><?php esc_html_e('There is no holiday list for that country.', 'ritmo-revenue'); ?></p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url($view['post_url']); ?>">
                    <?php wp_nonce_field('ritmo_holidays_stage'); ?>
                    <input type="hidden" name="action" value="ritmo_holidays_stage">
                    <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
                    <input type="hidden" name="tab" value="calendar">
                    <input type="hidden" name="country" value="<?php echo esc_attr($view['import']['country']); ?>">
                    <input type="hidden" name="bridges" value="<?php echo esc_attr($view['import']['bridges'] ? '1' : '0'); ?>">
                    <?php foreach ($view['import']['years'] as $ritmo_year) : ?><input type="hidden" name="years[]" value="<?php echo esc_attr((string) $ritmo_year); ?>"><?php endforeach; ?>
                    <table class="widefat striped ritmo-table">
                        <caption><?php esc_html_e('Holiday periods proposed', 'ritmo-revenue'); ?></caption>
                        <thead><tr><th scope="col"><?php esc_html_e('Add', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Holiday', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Dates', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Nights proposed', 'ritmo-revenue'); ?></th></tr></thead>
                        <tbody>
                            <?php foreach ($view['import']['rows'] as $ritmo_i => $ritmo_row) : ?>
                                <tr data-ritmo="proposal-row">
                                    <td data-label="<?php esc_attr_e('Add', 'ritmo-revenue'); ?>"><input type="checkbox" id="ritmo-proposal-<?php echo esc_attr((string) $ritmo_i); ?>" name="keys[]" value="<?php echo esc_attr($ritmo_row['key']); ?>" <?php checked($ritmo_row['ticked']); ?>></td>
                                    <th scope="row" data-label="<?php esc_attr_e('Holiday', 'ritmo-revenue'); ?>"><label for="ritmo-proposal-<?php echo esc_attr((string) $ritmo_i); ?>"><?php echo esc_html($ritmo_row['name']); ?></label>
                                        <?php if ($ritmo_row['optional']) : ?><br><span class="ritmo-muted"><?php esc_html_e('optional day off', 'ritmo-revenue'); ?></span><?php endif; ?>
                                        <?php if ($ritmo_row['listed']) : ?><br><span class="ritmo-muted"><?php esc_html_e('already in your list', 'ritmo-revenue'); ?></span><?php endif; ?>
                                        <?php if ($ritmo_row['weekend']) : ?><br><span class="ritmo-muted"><?php esc_html_e('falls on a weekend', 'ritmo-revenue'); ?></span><?php endif; ?>
                                    </th>
                                    <td data-label="<?php esc_attr_e('Dates', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['dates']); ?></td>
                                    <td data-label="<?php esc_attr_e('Nights proposed', 'ritmo-revenue'); ?>"><?php echo esc_html(sprintf(/* translators: 1: range of nights, 2: number of nights */ _n('%1$s · %2$d night', '%1$s · %2$d nights', (int) $ritmo_row['nights'], 'ritmo-revenue'), $ritmo_row['range'], (int) $ritmo_row['nights'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (!$view['read_only']) : ?><p><button type="submit" class="button button-primary"><?php esc_html_e('Add the ticked periods to my draft', 'ritmo-revenue'); ?></button></p><?php endif; ?>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>
