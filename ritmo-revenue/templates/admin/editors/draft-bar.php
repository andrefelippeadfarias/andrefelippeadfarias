<?php
/**
 * The bar shown on every tab of the Rules screen while the person has unsaved changes.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
?>
<?php if (($view['draft_changes'] ?? 0) > 0) : ?>
    <div class="ritmo-draft-bar" data-ritmo="draft-bar" role="region" aria-label="<?php esc_attr_e('Unsaved changes', 'ritmo-revenue'); ?>">
        <p>
            <?php echo esc_html(sprintf(/* translators: %d: number of settings changed */ _n('You have unsaved changes to %d setting.', 'You have unsaved changes to %d settings.', (int) $view['draft_changes'], 'ritmo-revenue'), (int) $view['draft_changes'])); ?>
            <?php if ($ritmo_page->tab !== 'review') : ?>
                <a class="button button-primary" href="<?php echo esc_url($ritmo_page->url(['tab' => 'review'])); ?>"><?php esc_html_e('Review and save', 'ritmo-revenue'); ?></a>
            <?php endif; ?>
        </p>
        <form method="post" action="<?php echo esc_url($view['post_url']); ?>">
            <?php wp_nonce_field('ritmo_rules_draft_discard'); ?>
            <input type="hidden" name="action" value="ritmo_rules_draft_discard">
            <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_page->property?->id ?? ''); ?>">
            <input type="hidden" name="tab" value="<?php echo esc_attr($ritmo_page->tab); ?>">
            <button type="submit" class="button" data-ritmo="discard-draft"><?php esc_html_e('Discard', 'ritmo-revenue'); ?></button>
        </form>
    </div>
<?php endif; ?>
