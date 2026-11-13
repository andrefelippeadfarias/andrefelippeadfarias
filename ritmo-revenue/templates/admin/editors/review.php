<?php
/**
 * The review tab: what the draft changes, its errors, the safety confirmation, conflicts with a
 * newer version and the plans waiting for approval; then "Save as a new version".
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
$ritmo_review = $view['review'];
?>
<?php if ($ritmo_review === null) : ?>
    <div class="ritmo-empty" data-ritmo="empty">
        <h2><?php esc_html_e('Nothing to review', 'ritmo-revenue'); ?></h2>
        <p><?php esc_html_e('You have no unsaved change. Change a rule in another tab: it goes into your draft, and you save it here.', 'ritmo-revenue'); ?></p>
    </div>
<?php else : ?>
    <?php View::render('admin/editors/draft-bar', $view); ?>
    <?php if ($ritmo_review['errors'] !== []) : ?>
        <div class="ritmo-error-summary" role="alert" tabindex="-1" data-ritmo="error-summary">
            <h2><?php esc_html_e('Correct these values before saving', 'ritmo-revenue'); ?></h2>
            <ul>
                <?php foreach ($ritmo_review['errors'] as $ritmo_error) : ?>
                    <li><a href="<?php echo esc_url($ritmo_error['url']); ?>"><?php echo esc_html($ritmo_error['text']); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($ritmo_review['conflicts'] !== []) : ?>
        <section class="ritmo-card" data-ritmo="conflict">
            <h2><?php esc_html_e('Changed by someone else after you started', 'ritmo-revenue'); ?></h2>
            <ul>
                <?php foreach ($ritmo_review['conflicts'] as $ritmo_conflict) : ?>
                    <li>
                        <?php echo esc_html(sprintf(/* translators: 1: setting, 2: value now in force, 3: value of the draft */ __('%1$s is now %2$s; your draft says %3$s.', 'ritmo-revenue'), $ritmo_conflict['label'], $ritmo_conflict['theirs'], $ritmo_conflict['mine'])); ?>
                        <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-inline">
                            <?php wp_nonce_field('ritmo_rules_keep_theirs'); ?>
                            <input type="hidden" name="action" value="ritmo_rules_keep_theirs">
                            <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_page->property?->id ?? ''); ?>">
                            <input type="hidden" name="tab" value="review">
                            <input type="hidden" name="path" value="<?php echo esc_attr($ritmo_conflict['path']); ?>">
                            <button type="submit" class="button"><?php esc_html_e('Keep the value in force', 'ritmo-revenue'); ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <table class="widefat striped ritmo-table" data-ritmo="review-table">
        <caption><?php echo esc_html(sprintf(/* translators: %d: number of changes */ _n('%d change from the rules in force', '%d changes from the rules in force', count($ritmo_review['rows']), 'ritmo-revenue'), count($ritmo_review['rows']))); ?></caption>
        <thead><tr>
            <th scope="col"><?php esc_html_e('Setting', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('In force', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('Your draft', 'ritmo-revenue'); ?></th>
            <th scope="col"><?php esc_html_e('Default', 'ritmo-revenue'); ?></th>
        </tr></thead>
        <tbody>
            <?php foreach ($ritmo_review['rows'] as $ritmo_row) : ?>
                <tr data-ritmo="review-row">
                    <th scope="row" data-label="<?php esc_attr_e('Setting', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['label']); ?><?php if ($ritmo_row['safety']) : ?> <span class="ritmo-badge ritmo-tone-attention"><?php esc_html_e('Safety limit', 'ritmo-revenue'); ?></span><?php endif; ?></th>
                    <td data-label="<?php esc_attr_e('In force', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['before']); ?></td>
                    <td data-label="<?php esc_attr_e('Your draft', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['after']); ?></td>
                    <td data-label="<?php esc_attr_e('Default', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['default']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($view['may_edit']) : ?>
        <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card ritmo-form">
            <?php wp_nonce_field('ritmo_rules_save'); ?>
            <input type="hidden" name="action" value="ritmo_rules_save">
            <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_page->property?->id ?? ''); ?>">
            <input type="hidden" name="tab" value="review">
            <?php foreach ($ritmo_review['waiting'] as $ritmo_property_id => $ritmo_nights) : ?>
                <p class="ritmo-callout ritmo-tone-attention"><?php echo esc_html(sprintf(/* translators: 1: property name, 2: number of nights */ _n('Saving ends the plan waiting for approval of %1$s (%2$d night). The next run proposes a new one with these rules.', 'Saving ends the plan waiting for approval of %1$s (%2$d nights). The next run proposes a new one with these rules.', (int) $ritmo_nights, 'ritmo-revenue'), $ritmo_review['property_names'][$ritmo_property_id] ?? $ritmo_property_id, (int) $ritmo_nights)); ?></p>
            <?php endforeach; ?>
            <?php if ($ritmo_review['safety'] !== null) : ?>
                <fieldset class="ritmo-safety" data-ritmo="safety-confirm">
                    <legend><?php esc_html_e('Safety limits', 'ritmo-revenue'); ?></legend>
                    <ul>
                        <?php foreach ($ritmo_review['safety']['rows'] as $ritmo_row) : ?>
                            <li><?php echo esc_html(sprintf(/* translators: 1: setting, 2: value in force, 3: new value */ __('%1$s: %2$s → %3$s', 'ritmo-revenue'), $ritmo_row['label'], $ritmo_row['before'], $ritmo_row['after'])); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($ritmo_review['safety']['allowed']) : ?>
                        <label for="ritmo-confirmation"><?php esc_html_e('To confirm, type exactly:', 'ritmo-revenue'); ?> <code><?php echo esc_html($ritmo_review['safety']['expected']); ?></code></label>
                        <input type="text" id="ritmo-confirmation" name="confirmation" autocomplete="off" data-ritmo-js="confirm-typed" data-expected="<?php echo esc_attr($ritmo_review['safety']['expected']); ?>">
                    <?php else : ?>
                        <p><?php esc_html_e('Only a person allowed to change safety limits can save these changes.', 'ritmo-revenue'); ?></p>
                    <?php endif; ?>
                </fieldset>
            <?php endif; ?>
            <p>
                <label for="ritmo-note"><?php esc_html_e('Note for the history (optional)', 'ritmo-revenue'); ?></label><br>
                <input type="text" id="ritmo-note" name="note" maxlength="190" class="regular-text">
            </p>
            <p class="ritmo-help"><?php esc_html_e('Saving makes a new version of the rules. It never changes a price now: it applies from the next run.', 'ritmo-revenue'); ?></p>
            <p><button type="submit" class="button button-primary" data-ritmo="save-version" <?php disabled($ritmo_review['errors'] !== [] || $ritmo_review['conflicts'] !== []); ?>><?php esc_html_e('Save as a new version', 'ritmo-revenue'); ?></button></p>
        </form>
    <?php endif; ?>
<?php endif; ?>
