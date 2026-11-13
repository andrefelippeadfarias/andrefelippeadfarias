<?php
/**
 * Wizard step 9: what the last shadow run would change, refused nights and what happens next.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_c = $view['c'];
?>
<h2><?php esc_html_e('What the plugin would do', 'ritmo-revenue'); ?></h2>
<?php if ($ritmo_c['empty']) : ?>
    <p data-ritmo="empty"><?php esc_html_e('No shadow run yet: run one in step 8.', 'ritmo-revenue'); ?></p>
<?php else : ?>
    <div data-ritmo="would-do-summary">
        <p class="ritmo-big"><?php echo esc_html($ritmo_c['sentence']); ?></p>
        <?php if ($ritmo_c['nearest'] !== []) : ?>
            <ul class="ritmo-list">
                <?php foreach ($ritmo_c['nearest'] as $ritmo_n) : ?>
                    <li><strong><?php echo esc_html($ritmo_n['title']); ?></strong>: <?php View::render('admin/screens/parts/price', ['price' => $ritmo_n['change']]); ?> · <?php echo esc_html($ritmo_n['reason']); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($ritmo_c['refused'] !== []) : ?>
            <h3><?php esc_html_e('Nights the safety checks refused', 'ritmo-revenue'); ?></h3>
            <ul class="ritmo-list"><?php foreach ($ritmo_c['refused'] as $ritmo_r) : ?><li><?php echo esc_html($ritmo_r); ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <h3><?php esc_html_e('What happens next', 'ritmo-revenue'); ?></h3>
        <p><?php echo esc_html($ritmo_c['next']); ?></p>
        <p class="ritmo-actions">
            <a class="button button-primary" href="<?php echo esc_url($ritmo_c['overview_url']); ?>"><?php esc_html_e('Go to the overview', 'ritmo-revenue'); ?></a>
            <a class="button" href="<?php echo esc_url($ritmo_c['nights_url']); ?>"><?php esc_html_e('See every night', 'ritmo-revenue'); ?></a>
        </p>
    </div>
<?php endif; ?>
