<?php
/**
 * Top of every screen: title, the tabs of the screens the user may open and the pending notice.
 *
 * @var array<string, mixed> $view page, pages, notice
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<div class="wrap ritmo">
    <h1 class="ritmo-title"><?php esc_html_e('Ritmo Revenue', 'ritmo-revenue'); ?></h1>
    <nav class="nav-tab-wrapper ritmo-tabs">
        <?php foreach ($view['pages'] as $slug => $label) : ?>
            <a class="nav-tab<?php echo esc_attr($slug === $view['page'] ? ' nav-tab-active' : ''); ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . $slug)); ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if (is_array($view['notice'])) : ?>
        <div class="notice notice-<?php echo esc_attr($view['notice']['type']); ?> ritmo-notice" role="status">
            <p><?php echo esc_html($view['notice']['message']); ?></p>
            <?php if ($view['notice']['details'] !== []) : ?>
                <ul class="ritmo-details">
                    <?php foreach ($view['notice']['details'] as $line) : ?>
                        <li><code><?php echo esc_html($line); ?></code></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
