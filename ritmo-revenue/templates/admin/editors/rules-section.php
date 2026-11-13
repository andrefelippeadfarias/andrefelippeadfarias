<?php
/**
 * One section of the general tab, or the safety tab, of the Rules screen: the fields of the
 * schema drawn from the person's draft (or the rules in force), posted into the draft.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
View::render('admin/editors/draft-bar', $view);
?>
<p class="ritmo-intro"><?php esc_html_e('Changing a rule never changes a price by itself: your changes stay in a draft until you save them as a new version, which applies from the next run.', 'ritmo-revenue'); ?></p>

<?php if ($view['scope_tab'] === 'safety') : ?>
    <p class="ritmo-callout"><?php esc_html_e('These limits protect your prices. Changing them needs the permission to change safety limits and typing the new value to confirm.', 'ritmo-revenue'); ?></p>
    <?php if ($view['read_only']) : ?>
        <p class="ritmo-callout ritmo-tone-attention" data-ritmo="safety-read-only"><?php esc_html_e('Only a person allowed to change safety limits can change these.', 'ritmo-revenue'); ?></p>
    <?php endif; ?>
<?php endif; ?>

<?php if (isset($view['sections'])) : ?>
    <nav class="ritmo-sections" aria-label="<?php esc_attr_e('Sections of the rules', 'ritmo-revenue'); ?>" data-ritmo="section-nav">
        <ul>
            <?php foreach ($view['sections'] as $ritmo_id => $ritmo_title) : ?>
                <li><a href="<?php echo esc_url($ritmo_page->url(['section' => $ritmo_id])); ?>" <?php if ($ritmo_id === $view['section']) : ?>aria-current="page"<?php endif; ?>><?php echo esc_html($ritmo_title); ?></a></li>
            <?php endforeach; ?>
        </ul>
        <form method="get" class="ritmo-section-select">
            <input type="hidden" name="page" value="<?php echo esc_attr($ritmo_page->slug); ?>">
            <input type="hidden" name="tab" value="general">
            <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_page->property?->id ?? ''); ?>">
            <label for="ritmo-section"><?php esc_html_e('Section', 'ritmo-revenue'); ?></label>
            <select id="ritmo-section" name="section">
                <?php foreach ($view['sections'] as $ritmo_id => $ritmo_title) : ?>
                    <option value="<?php echo esc_attr($ritmo_id); ?>" <?php selected($ritmo_id, $view['section']); ?>><?php echo esc_html($ritmo_title); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button"><?php esc_html_e('Go', 'ritmo-revenue'); ?></button>
        </form>
    </nav>
    <h2><?php echo esc_html($view['sections'][$view['section']]); ?></h2>
<?php endif; ?>

<?php if ($view['nodes'] === []) : ?>
    <p data-ritmo="empty"><?php esc_html_e('Nothing to set here.', 'ritmo-revenue'); ?></p>
<?php else : ?>
    <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-form ritmo-rules-form" novalidate>
        <?php wp_nonce_field('ritmo_rules_draft_update'); ?>
        <input type="hidden" name="action" value="ritmo_rules_draft_update">
        <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_page->property?->id ?? ''); ?>">
        <input type="hidden" name="tab" value="<?php echo esc_attr($view['scope_tab']); ?>">
        <input type="hidden" name="scope" value="<?php echo esc_attr($view['scope']); ?>">
        <?php foreach ($view['nodes'] as $ritmo_node) : ?>
            <?php View::render('admin/editors/field', ['node' => $ritmo_node]); ?>
        <?php endforeach; ?>
        <?php if (!$view['read_only']) : ?>
            <p><button type="submit" class="button button-primary" data-ritmo="keep-draft"><?php esc_html_e('Keep these changes in my draft', 'ritmo-revenue'); ?></button></p>
        <?php endif; ?>
    </form>
<?php endif; ?>
