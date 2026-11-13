<?php
/**
 * Wizard step 1: what the plugin does and the checks of the site.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<h2><?php esc_html_e('Welcome and checks', 'ritmo-revenue'); ?></h2>
<p><?php esc_html_e('Ritmo Revenue reads your bookings and calendar, decides floors and ceilings of every night with your rules, and checks every change against safety limits. It starts in shadow mode: it writes nothing until a person approves.', 'ritmo-revenue'); ?></p>
<ul class="ritmo-checks">
    <?php foreach ($view['c']['checks'] as $ritmo_check) : ?>
        <li><span class="dashicons <?php echo esc_attr($ritmo_check['ok'] ? 'dashicons-yes' : 'dashicons-warning'); ?>" aria-hidden="true"></span> <?php echo esc_html($ritmo_check['ok'] ? __('OK:', 'ritmo-revenue') : __('Attention:', 'ritmo-revenue')); ?> <?php echo esc_html($ritmo_check['text']); ?></li>
    <?php endforeach; ?>
</ul>
<p><a class="button button-primary" data-ritmo="wizard-continue" href="<?php echo esc_url(add_query_arg(['page' => 'ritmo-revenue-settings', 'tab' => 'setup', 'step' => 2], admin_url('admin.php'))); ?>"><?php esc_html_e('Continue', 'ritmo-revenue'); ?></a></p>
