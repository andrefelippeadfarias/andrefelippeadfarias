<?php
/**
 * Settings › Setup: the wizard's steps and the form of the step shown.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_c = $view['content'];
?>
<div class="ritmo-wizard">
    <nav aria-label="<?php esc_attr_e('Steps of the setup', 'ritmo-revenue'); ?>">
        <ol class="ritmo-steps">
            <?php foreach ($view['steps'] as $ritmo_s) : ?>
                <li data-ritmo="wizard-step-<?php echo esc_attr((string) $ritmo_s['number']); ?>"<?php if ($ritmo_s['current']) : ?> aria-current="step" data-ritmo-current="1"<?php endif; ?>>
                    <a href="<?php echo esc_url($ritmo_s['url']); ?>"><?php echo esc_html($ritmo_s['title']); ?></a>
                    <?php if ($ritmo_s['done']) : ?><span class="ritmo-done"><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e('done', 'ritmo-revenue'); ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>
    <section class="ritmo-card ritmo-wide" data-ritmo="wizard-current">
        <?php if (!$view['can']) : ?>
            <p class="notice notice-info inline"><?php esc_html_e('Ask an administrator to do this step.', 'ritmo-revenue'); ?></p>
        <?php endif; ?>
        <?php View::render('admin/screens/wizard/' . $view['template'], ['c' => $ritmo_c, 'can' => $view['can'], 'property_id' => $view['property_id'], 'safety_note' => $view['safety_note']]); ?>
        <?php if ($view['back'] !== '') : ?><p><a href="<?php echo esc_url($view['back']); ?>"><?php esc_html_e('Back', 'ritmo-revenue'); ?></a></p><?php endif; ?>
    </section>
</div>
