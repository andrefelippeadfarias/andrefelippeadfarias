<?php
/**
 * The frame of the rules, orders and market screens: title, property picker, notice of the last
 * action, tabs, then the screen's own template.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
$ritmo_notice = $view['notice'];
?>
<div class="wrap ritmo ritmo-editors" data-ritmo-screen="<?php echo esc_attr($ritmo_page->slug); ?>">
    <div class="ritmo-head">
        <h1><?php echo esc_html(sprintf(/* translators: %s: name of the screen */ __('Ritmo Revenue — %s', 'ritmo-revenue'), $ritmo_page->title)); ?></h1>
        <?php if (count($ritmo_page->properties) > 1) : ?>
            <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-picker" data-ritmo="property-picker">
                <?php wp_nonce_field('ritmo_select_property'); ?>
                <input type="hidden" name="action" value="ritmo_select_property">
                <input type="hidden" name="page" value="<?php echo esc_attr($ritmo_page->slug); ?>">
                <input type="hidden" name="tab" value="<?php echo esc_attr($ritmo_page->tab); ?>">
                <label for="ritmo-property"><?php esc_html_e('Property', 'ritmo-revenue'); ?></label>
                <select id="ritmo-property" name="property">
                    <?php foreach ($ritmo_page->properties as $ritmo_option) : ?>
                        <option value="<?php echo esc_attr($ritmo_option->id); ?>" <?php selected($ritmo_option->id, $ritmo_page->property?->id); ?>><?php echo esc_html($ritmo_option->name); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e('Show', 'ritmo-revenue'); ?></button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (is_array($ritmo_notice)) : ?>
        <div class="notice notice-<?php echo esc_attr($ritmo_notice['type']); ?> ritmo-notice" data-ritmo="notice" role="<?php echo esc_attr($ritmo_notice['type'] === 'error' ? 'alert' : 'status'); ?>">
            <p><?php echo esc_html($ritmo_notice['message']); ?></p>
            <?php if ($ritmo_notice['details'] !== []) : ?>
                <ul>
                    <?php foreach ($ritmo_notice['details'] as $ritmo_detail) : ?>
                        <li><?php echo esc_html($ritmo_detail); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (count($view['tabs']) > 1) : ?>
        <nav class="nav-tab-wrapper ritmo-tabs" aria-label="<?php esc_attr_e('Sections of this screen', 'ritmo-revenue'); ?>">
            <?php foreach ($view['tabs'] as $ritmo_tab) : ?>
                <a class="nav-tab<?php echo esc_attr($ritmo_tab['current'] ? ' nav-tab-active' : ''); ?>" href="<?php echo esc_url($ritmo_tab['url']); ?>" <?php if ($ritmo_tab['current']) : ?>aria-current="page"<?php endif; ?>><?php echo esc_html($ritmo_tab['title']); ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php View::render($view['template'], ['page' => $ritmo_page, 'post_url' => $view['post_url']] + $view['view']); ?>
</div>
