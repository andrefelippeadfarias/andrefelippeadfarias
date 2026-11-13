<?php
/**
 * The frame of a central screen: the header (parts/head), then the screen's own template.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
?>
<div class="wrap ritmo ritmo-screen" data-ritmo-screen="<?php echo esc_attr($ritmo_page->slug); ?>">
    <?php View::render('admin/screens/parts/head', $view); ?>

    <?php View::render('admin/screens/' . $view['template'], ['page' => $ritmo_page] + $view['view']); ?>
</div>
