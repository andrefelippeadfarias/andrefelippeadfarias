<?php
/**
 * The 12-month review's state and the "Save this review" form.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
$ritmo_due = $view['due'];
?>
<div class="ritmo-callout" data-ritmo="review-due" role="status">
    <p>
        <?php if ($ritmo_due->daysSinceLast === null) : ?>
            <?php esc_html_e('Save the first review to compare the next one with it.', 'ritmo-revenue'); ?>
        <?php elseif ($ritmo_due->due) : ?>
            <?php echo esc_html(sprintf(/* translators: %d: days since the last review */ _n('The last review is %d day old: save a new one.', 'The last review is %d days old: save a new one.', $ritmo_due->daysSinceLast, 'ritmo-revenue'), $ritmo_due->daysSinceLast)); ?>
        <?php else : ?>
            <?php echo esc_html(sprintf(/* translators: %d: days since the last review */ _n('The last review is %d day old.', 'The last review is %d days old.', $ritmo_due->daysSinceLast, 'ritmo-revenue'), $ritmo_due->daysSinceLast)); ?>
        <?php endif; ?>
    </p>
    <?php if ($view['may_edit']) : ?>
        <form method="post" action="<?php echo esc_url($view['post_url']); ?>">
            <?php wp_nonce_field('ritmo_review_snapshot'); ?>
            <input type="hidden" name="action" value="ritmo_review_snapshot">
            <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_page->property->id); ?>">
            <button type="submit" class="button button-primary"><?php esc_html_e('Save this review', 'ritmo-revenue'); ?></button>
        </form>
    <?php endif; ?>
</div>
