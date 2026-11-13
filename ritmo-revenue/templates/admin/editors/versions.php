<?php
/**
 * The versions tab: every version of the rules, the comparison of two, restore, and the preset
 * file (download and upload).
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
View::render('admin/editors/draft-bar', $view);
?>
<?php if ($view['rows'] === []) : ?>
    <div class="ritmo-empty" data-ritmo="empty"><p><?php esc_html_e('No version of the rules yet: the neutral defaults apply until the first one is saved.', 'ritmo-revenue'); ?></p></div>
<?php else : ?>
    <table class="widefat striped ritmo-table" data-ritmo="versions-table" data-rest-url="<?php echo esc_url($view['compare_rest']['url']); ?>" data-rest-nonce="<?php echo esc_attr($view['compare_rest']['nonce']); ?>" data-property="<?php echo esc_attr($view['compare_rest']['property']); ?>" data-error="<?php esc_attr_e('The changes could not be loaded. Use the link to compare the two versions.', 'ritmo-revenue'); ?>">
        <caption><?php echo esc_html(sprintf(/* translators: %d: number of versions */ _n('%d version of the rules', '%d versions of the rules', (int) $view['total'], 'ritmo-revenue'), (int) $view['total'])); ?></caption>
        <thead><tr>
            <th scope="col"><?php esc_html_e('Version', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('Saved at', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('By', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('From', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('Note', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('Changes', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('Actions', 'ritmo-revenue'); ?></th>
        </tr></thead>
        <tbody>
            <?php foreach ($view['rows'] as $ritmo_row) : ?>
                <tr>
                    <th scope="row" data-label="<?php esc_attr_e('Version', 'ritmo-revenue'); ?>"><?php echo esc_html((string) $ritmo_row['version']); ?><?php if ($ritmo_row['in_force']) : ?> <span class="ritmo-badge ritmo-tone-good"><?php esc_html_e('In force', 'ritmo-revenue'); ?></span><?php endif; ?></th>
                    <td data-label="<?php esc_attr_e('Saved at', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['at']); ?></td>
                    <td data-label="<?php esc_attr_e('By', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['by']); ?></td>
                    <td data-label="<?php esc_attr_e('From', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['source']); ?></td>
                    <td data-label="<?php esc_attr_e('Note', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['note']); ?></td>
                    <td data-label="<?php esc_attr_e('Changes', 'ritmo-revenue'); ?>">
                        <?php if ($ritmo_row['changes'] === null) : ?>
                            <?php esc_html_e('first version', 'ritmo-revenue'); ?>
                        <?php else : ?>
                            <a href="<?php echo esc_url($ritmo_page->url(['from' => $ritmo_row['version'] - 1, 'to' => $ritmo_row['version']])); ?>"><?php echo esc_html(sprintf(/* translators: %d: number of changes */ _n('%d change', '%d changes', (int) $ritmo_row['changes'], 'ritmo-revenue'), (int) $ritmo_row['changes'])); ?></a>
                            <?php if ((int) $ritmo_row['changes'] > 0) : ?>
                                <button type="button" class="button-link" hidden data-ritmo-js="version-changes" data-ritmo="version-changes-<?php echo esc_attr((string) $ritmo_row['version']); ?>" data-from="<?php echo esc_attr((string) ($ritmo_row['version'] - 1)); ?>" data-to="<?php echo esc_attr((string) $ritmo_row['version']); ?>" aria-expanded="false"><?php esc_html_e('Show them here', 'ritmo-revenue'); ?></button>
                                <ul class="ritmo-version-changes" hidden></ul>
                                <p class="ritmo-muted" role="status"></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?php esc_attr_e('Actions', 'ritmo-revenue'); ?>">
                        <?php if ($view['may_edit'] && !$ritmo_row['in_force']) : ?>
                            <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-inline">
                                <?php wp_nonce_field('ritmo_rules_restore:' . $ritmo_row['version']); ?>
                                <input type="hidden" name="action" value="ritmo_rules_restore">
                                <input type="hidden" name="version" value="<?php echo esc_attr((string) $ritmo_row['version']); ?>">
                                <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_page->property?->id ?? ''); ?>">
                                <input type="hidden" name="tab" value="versions">
                                <button type="submit" class="button" data-ritmo="restore-<?php echo esc_attr((string) $ritmo_row['version']); ?>"><?php echo esc_html(sprintf(/* translators: %d: version number */ __('Restore version %d', 'ritmo-revenue'), $ritmo_row['version'])); ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($view['total'] > 20) : ?>
        <p class="ritmo-paging">
            <?php if ($view['page_number'] > 1) : ?><a href="<?php echo esc_url($ritmo_page->url(['paged' => $view['page_number'] - 1])); ?>"><?php esc_html_e('Newer versions', 'ritmo-revenue'); ?></a><?php endif; ?>
            <?php if ($view['page_number'] * 20 < $view['total']) : ?><a href="<?php echo esc_url($ritmo_page->url(['paged' => $view['page_number'] + 1])); ?>"><?php esc_html_e('Older versions', 'ritmo-revenue'); ?></a><?php endif; ?>
        </p>
    <?php endif; ?>
<?php endif; ?>

<section class="ritmo-card">
    <h2><?php esc_html_e('Compare two versions', 'ritmo-revenue'); ?></h2>
    <form method="get" class="ritmo-filters">
        <input type="hidden" name="page" value="<?php echo esc_attr($ritmo_page->slug); ?>">
        <input type="hidden" name="tab" value="versions">
        <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_page->property?->id ?? ''); ?>">
        <label for="ritmo-from"><?php esc_html_e('From version', 'ritmo-revenue'); ?></label>
        <select id="ritmo-from" name="from">
            <?php for ($ritmo_v = (int) $view['total']; $ritmo_v >= 1; --$ritmo_v) : ?>
                <option value="<?php echo esc_attr((string) $ritmo_v); ?>" <?php selected(($view['compare']['from'] ?? max(1, (int) $view['total'] - 1)) === $ritmo_v); ?>><?php echo esc_html((string) $ritmo_v); ?></option>
            <?php endfor; ?>
        </select>
        <label for="ritmo-to"><?php esc_html_e('To version', 'ritmo-revenue'); ?></label>
        <select id="ritmo-to" name="to">
            <?php for ($ritmo_v = (int) $view['total']; $ritmo_v >= 1; --$ritmo_v) : ?>
                <option value="<?php echo esc_attr((string) $ritmo_v); ?>" <?php selected(($view['compare']['to'] ?? (int) $view['total']) === $ritmo_v); ?>><?php echo esc_html((string) $ritmo_v); ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit" class="button"><?php esc_html_e('Compare', 'ritmo-revenue'); ?></button>
    </form>
    <?php if ($view['compare'] !== null) : ?>
        <?php if (isset($view['compare']['error'])) : ?>
            <p class="ritmo-field-error"><?php echo esc_html($view['compare']['error']); ?></p>
        <?php elseif ($view['compare']['rows'] === []) : ?>
            <p><?php esc_html_e('The two versions have the same rules.', 'ritmo-revenue'); ?></p>
        <?php else : ?>
            <table class="widefat striped ritmo-table" data-ritmo="compare-table">
                <caption><?php echo esc_html(sprintf(/* translators: 1: first version, 2: second version */ __('From version %1$d to version %2$d', 'ritmo-revenue'), $view['compare']['from'], $view['compare']['to'])); ?></caption>
                <thead><tr><th scope="col"><?php esc_html_e('Setting', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Before', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('After', 'ritmo-revenue'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($view['compare']['rows'] as $ritmo_row) : ?>
                        <tr><th scope="row" data-label="<?php esc_attr_e('Setting', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['label']); ?></th><td data-label="<?php esc_attr_e('Before', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['before']); ?></td><td data-label="<?php esc_attr_e('After', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['after']); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</section>

<div class="ritmo-grid">
    <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card">
        <?php wp_nonce_field('ritmo_rules_export'); ?>
        <input type="hidden" name="action" value="ritmo_rules_export">
        <h2><?php esc_html_e('Download the rules in force', 'ritmo-revenue'); ?></h2>
        <p><?php esc_html_e('A preset file with the rules, the properties and their rooms. It holds no key and no booking.', 'ritmo-revenue'); ?></p>
        <button type="submit" class="button"><?php esc_html_e('Download the preset file', 'ritmo-revenue'); ?></button>
    </form>
    <?php if ($view['may_edit']) : ?>
        <form method="post" action="<?php echo esc_url($view['post_url']); ?>" enctype="multipart/form-data" class="ritmo-card">
            <?php wp_nonce_field('ritmo_rules_import'); ?>
            <input type="hidden" name="action" value="ritmo_rules_import">
            <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_page->property?->id ?? ''); ?>">
            <input type="hidden" name="tab" value="versions">
            <h2><?php esc_html_e('Load rules from a preset file', 'ritmo-revenue'); ?></h2>
            <p><label for="ritmo-preset-file"><?php esc_html_e('Preset file (.json, up to 1 MB)', 'ritmo-revenue'); ?></label><br><input type="file" id="ritmo-preset-file" name="preset_file" accept=".json,application/json"></p>
            <p class="ritmo-help"><?php esc_html_e('The rules of the file go into your draft; you review and save them.', 'ritmo-revenue'); ?></p>
            <button type="submit" class="button"><?php esc_html_e('Load into my draft', 'ritmo-revenue'); ?></button>
        </form>
    <?php endif; ?>
</div>
