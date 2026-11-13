<?php
/**
 * The frame of the rules, orders, market and reports screens: the same header as every screen
 * (screens/parts/head), then the screen's own template.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
?>
<div class="wrap ritmo ritmo-editors" data-ritmo-screen="<?php echo esc_attr($ritmo_page->slug); ?>">
    <?php View::render('admin/screens/parts/head', $view); ?>

    <?php View::render($view['template'], ['page' => $ritmo_page, 'post_url' => $view['post_url']] + $view['view']); ?>
</div>
