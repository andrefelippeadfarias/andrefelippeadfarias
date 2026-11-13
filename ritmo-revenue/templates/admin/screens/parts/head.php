<?php
/**
 * The header of every screen of the plugin: title, property picker, emergency banner, status
 * strip, the notice of the last action and the tabs.
 *
 * @var array<string, mixed> $view page (slug, tab), header (Page::headerOf) and notice
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
$ritmo_header = $view['header'];
$ritmo_notice = $view['notice'];
?>
<div class="ritmo-head">
    <h1><?php echo esc_html($ritmo_header['h1']); ?></h1>
    <?php if ($ritmo_header['picker'] !== []) : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ritmo-picker" data-ritmo="property-picker">
            <?php wp_nonce_field('ritmo_select_property'); ?>
            <input type="hidden" name="action" value="ritmo_select_property">
            <input type="hidden" name="page" value="<?php echo esc_attr($ritmo_page->slug); ?>">
            <input type="hidden" name="tab" value="<?php echo esc_attr($ritmo_page->tab); ?>">
            <label for="ritmo-property"><?php esc_html_e('Property', 'ritmo-revenue'); ?></label>
            <select id="ritmo-property" name="property">
                <?php foreach ($ritmo_header['picker'] as $ritmo_option) : ?>
                    <option value="<?php echo esc_attr($ritmo_option['id']); ?>" <?php selected($ritmo_option['id'], $ritmo_header['selected']); ?>><?php echo esc_html($ritmo_option['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button"><?php esc_html_e('Show', 'ritmo-revenue'); ?></button>
        </form>
    <?php endif; ?>
</div>

<?php if ($ritmo_header['emergency']) : ?>
    <div class="notice notice-error ritmo-emergency" data-ritmo="emergency-banner" role="alert">
        <p>
            <strong><?php esc_html_e('Emergency switch engaged: nothing is written to the pricing system or the PMS. Scheduled runs only read.', 'ritmo-revenue'); ?></strong>
            <?php if ($ritmo_header['can_release']) : ?>
                <a href="<?php echo esc_url($ritmo_header['operations_url']); ?>"><?php esc_html_e('Release it in Operations', 'ritmo-revenue'); ?></a>
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<?php if ($ritmo_header['strip'] !== []) : ?>
    <div class="ritmo-strip" role="region" aria-label="<?php esc_attr_e('Status', 'ritmo-revenue'); ?>" data-ritmo="status-strip">
        <?php foreach ($ritmo_header['strip'] as $ritmo_item) : ?>
            <a class="ritmo-badge ritmo-tone-<?php echo esc_attr($ritmo_item['tone']); ?>" href="<?php echo esc_url($ritmo_item['url']); ?>" data-ritmo="<?php echo esc_attr($ritmo_item['hook']); ?>">
                <span class="dashicons <?php echo esc_attr($ritmo_item['icon']); ?>" aria-hidden="true"></span>
                <?php echo esc_html($ritmo_item['text']); ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php elseif ($ritmo_header['strip_failed']) : ?>
    <p class="ritmo-muted"><?php esc_html_e('The status could not be loaded. Nothing was changed.', 'ritmo-revenue'); ?></p>
<?php endif; ?>

<?php if (($ritmo_header['unreadable_rooms'] ?? []) !== []) : ?>
    <div class="notice notice-warning ritmo-notice" role="status" data-ritmo="unreadable-rooms">
        <p><?php
        echo esc_html(sprintf(
            /* translators: %s: room codes, separated by commas */
            __('Some rooms of the plugin could not be read and are left out of every screen and run: %s. Save them again in Settings, setup (step 5).', 'ritmo-revenue'),
            implode(', ', $ritmo_header['unreadable_rooms'])
        ));
        ?></p>
    </div>
<?php endif; ?>

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

<?php if ($ritmo_header['tabs'] !== []) : ?>
    <nav class="nav-tab-wrapper ritmo-tabs" aria-label="<?php esc_attr_e('Sections of this screen', 'ritmo-revenue'); ?>">
        <?php foreach ($ritmo_header['tabs'] as $ritmo_tab) : ?>
            <a class="nav-tab<?php echo esc_attr($ritmo_tab['current'] ? ' nav-tab-active' : ''); ?>" href="<?php echo esc_url($ritmo_tab['url']); ?>" <?php if ($ritmo_tab['current']) : ?>aria-current="page"<?php endif; ?>><?php echo esc_html($ritmo_tab['title']); ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
