<?php
/**
 * Wizard step 8: the first shadow run (it reads and decides; it writes nothing).
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$ritmo_c = $view['c'];
?>
<h2><?php esc_html_e('First shadow run', 'ritmo-revenue'); ?></h2>
<p><?php esc_html_e('The plugin reads the bookings and the calendars, decides every night and checks the plan against the safety rules. It writes nothing.', 'ritmo-revenue'); ?></p>
<?php if ($ritmo_c['property_id'] === '') : ?>
    <p data-ritmo="empty"><?php esc_html_e('Create the property first (step 4).', 'ritmo-revenue'); ?></p>
<?php elseif ($view['can']) : ?>
    <?php if ($ritmo_c['done']) : ?>
        <p class="notice notice-success inline"><?php esc_html_e('A shadow run is done.', 'ritmo-revenue'); ?> <a href="<?php echo esc_url($ritmo_c['next_url']); ?>"><?php esc_html_e('See what the plugin would do', 'ritmo-revenue'); ?></a></p>
    <?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('ritmo_run_shadow'); ?>
        <input type="hidden" name="action" value="ritmo_run_shadow">
        <input type="hidden" name="back" value="ritmo-revenue-settings">
        <input type="hidden" name="property_id" value="<?php echo esc_attr($ritmo_c['property_id']); ?>">
        <button type="submit" class="button button-primary" data-ritmo="run-shadow"
                data-rest-url="<?php echo esc_url($ritmo_c['rest_url'] . 'properties/' . rawurlencode($ritmo_c['property_id']) . '/shadow-run'); ?>"
                data-rest-nonce="<?php echo esc_attr($ritmo_c['rest_nonce']); ?>"
                data-text-running="<?php esc_attr_e('Running in shadow: reading and deciding, writing nothing…', 'ritmo-revenue'); ?>"
                data-text-failed="<?php esc_attr_e('The shadow run did not finish:', 'ritmo-revenue'); ?>"><?php $ritmo_c['done'] ? esc_html_e('Run in shadow again', 'ritmo-revenue') : esc_html_e('Run in shadow now', 'ritmo-revenue'); ?></button>
        <span class="ritmo-progress" role="status" data-ritmo="progress"></span>
    </form>
<?php endif; ?>
