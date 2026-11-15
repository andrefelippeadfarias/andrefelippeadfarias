<?php
/**
 * The frame of Settings › Licence: the header of every screen (screens/parts/head), then the
 * licence tab.
 *
 * @var array<string, mixed> $view page, header, notice and the tab's view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
?>
<div class="wrap ritmo ritmo-screen" data-ritmo-screen="<?php echo esc_attr($ritmo_page->slug); ?>">
    <?php View::render('admin/screens/parts/head', $view); ?>

    <?php View::render('admin/licence/screen', $view['view']); ?>
</div>
