<?php
/**
 * To-do: open items by due date, the done ones folded, and the form to add an item.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

if ($view['error'] !== null) :
    View::render('admin/screens/parts/card-error', $view);

    return;
endif;
?>
<section class="ritmo-card ritmo-wide">
    <h2><?php esc_html_e('Open', 'ritmo-revenue'); ?></h2>
    <?php if ($view['open'] === []) : ?>
        <p data-ritmo="empty"><?php esc_html_e('Nothing to do. The plugin adds items here when something needs a person.', 'ritmo-revenue'); ?></p>
    <?php else : ?>
        <ul class="ritmo-todo">
            <?php foreach ($view['open'] as $ritmo_item) : ?>
                <li data-ritmo="todo-row">
                    <strong><?php echo esc_html($ritmo_item['title']); ?></strong>
                    <?php if ($ritmo_item['due'] !== '') : ?><span class="ritmo-badge ritmo-tone-attention"><?php echo esc_html($ritmo_item['due']); ?></span><?php endif; ?>
                    <?php if ($ritmo_item['why'] !== '') : ?><p><?php echo esc_html($ritmo_item['why']); ?></p><?php endif; ?>
                    <p class="ritmo-muted"><?php echo esc_html($ritmo_item['by']); ?> · <?php echo esc_html($ritmo_item['at']); ?>
                        <?php if ($ritmo_item['where'] !== null) : ?> · <a href="<?php echo esc_url($ritmo_item['where']); ?>"><?php esc_html_e('Where to do it', 'ritmo-revenue'); ?></a><?php endif; ?></p>
                    <?php if ($view['can_edit']) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ritmo-inline">
                            <?php wp_nonce_field('ritmo_todo_close:' . $ritmo_item['id']); ?>
                            <input type="hidden" name="action" value="ritmo_todo_close">
                            <input type="hidden" name="item_id" value="<?php echo esc_attr((string) $ritmo_item['id']); ?>">
                            <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
                            <button type="submit" class="button" data-ritmo="todo-done"><?php esc_html_e('Done', 'ritmo-revenue'); ?></button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($view['done'] !== []) : ?>
    <details class="ritmo-card ritmo-wide">
        <summary><?php esc_html_e('Done', 'ritmo-revenue'); ?></summary>
        <ul class="ritmo-todo">
            <?php foreach ($view['done'] as $ritmo_item) : ?>
                <li>
                    <?php echo esc_html($ritmo_item['title']); ?>
                    <?php if ($view['can_edit']) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ritmo-inline">
                            <?php wp_nonce_field('ritmo_todo_reopen:' . $ritmo_item['id']); ?>
                            <input type="hidden" name="action" value="ritmo_todo_reopen">
                            <input type="hidden" name="item_id" value="<?php echo esc_attr((string) $ritmo_item['id']); ?>">
                            <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
                            <button type="submit" class="button-link"><?php esc_html_e('Open again', 'ritmo-revenue'); ?></button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </details>
<?php endif; ?>

<?php if ($view['can_edit']) : ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ritmo-card ritmo-form" data-ritmo="todo-add">
        <?php wp_nonce_field('ritmo_todo_add'); ?>
        <input type="hidden" name="action" value="ritmo_todo_add">
        <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
        <h2><?php esc_html_e('Add an item', 'ritmo-revenue'); ?></h2>
        <p><label for="ritmo-todo-title"><?php esc_html_e('What needs to be done', 'ritmo-revenue'); ?></label>
            <input type="text" id="ritmo-todo-title" name="title" maxlength="190" required class="regular-text"></p>
        <p><label for="ritmo-todo-details"><?php esc_html_e('Details (optional)', 'ritmo-revenue'); ?></label>
            <textarea id="ritmo-todo-details" name="details" maxlength="1000" rows="3" class="large-text"></textarea></p>
        <p><label for="ritmo-todo-due"><?php esc_html_e('Due on (optional)', 'ritmo-revenue'); ?></label>
            <input type="date" id="ritmo-todo-due" name="due_on"></p>
        <p class="description"><?php esc_html_e('Do not write guest names, e-mails, phone numbers or document numbers. Refer to a booking by its id.', 'ritmo-revenue'); ?></p>
        <button type="submit" class="button button-primary"><?php esc_html_e('Add', 'ritmo-revenue'); ?></button>
    </form>
<?php endif; ?>
