<?php
/**
 * The room relations tab: the order rooms are priced in, one card per relation with its sentence,
 * the price bands, and the forms to add or change them (staged into the draft).
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\View;

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
$ritmo_property = $ritmo_page->property?->id ?? '';
View::render('admin/editors/draft-bar', $view);
?>
<?php if ($view['rooms'] < 2) : ?>
    <div class="ritmo-empty" data-ritmo="empty"><p><?php esc_html_e('Relations need two rooms or more.', 'ritmo-revenue'); ?></p></div>
<?php else : ?>
    <?php if ($view['order'] !== []) : ?>
        <p data-ritmo="relation-order"><?php echo esc_html(sprintf(/* translators: %s: rooms in order */ __('Order in which rooms are priced: %s', 'ritmo-revenue'), implode(' → ', $view['order']))); ?></p>
    <?php endif; ?>

    <?php if ($view['relations'] === []) : ?>
        <div class="ritmo-empty" data-ritmo="empty">
            <p><?php esc_html_e('No relation: each room is priced on its own. Relations keep rooms in order, for example a bigger room never cheaper than a smaller one.', 'ritmo-revenue'); ?></p>
        </div>
    <?php endif; ?>

    <?php foreach ($view['relations'] as $ritmo_relation) : ?>
        <section class="ritmo-card ritmo-relation<?php echo esc_attr($ritmo_relation['enabled'] ? '' : ' ritmo-off'); ?>" data-ritmo="relation-card">
            <h3><?php echo esc_html($ritmo_relation['label']); ?> <span class="ritmo-badge ritmo-tone-<?php echo esc_attr($ritmo_relation['enabled'] ? 'good' : 'neutral'); ?>"><?php echo esc_html($ritmo_relation['enabled'] ? __('On', 'ritmo-revenue') : __('Off', 'ritmo-revenue')); ?></span></h3>
            <p data-ritmo="relation-sentence"><?php echo esc_html($ritmo_relation['sentence']); ?></p>
            <p class="ritmo-muted"><?php echo esc_html($ritmo_relation['kind'] . ' · ' . $ritmo_relation['nights']); ?></p>
            <?php if ($view['may_edit']) : ?>
                <div class="ritmo-actions">
                    <a class="button" href="<?php echo esc_url($ritmo_page->url(['edit' => $ritmo_relation['id']])); ?>"><?php esc_html_e('Edit', 'ritmo-revenue'); ?></a>
                    <?php foreach (['toggle' => $ritmo_relation['enabled'] ? __('Turn off', 'ritmo-revenue') : __('Turn on', 'ritmo-revenue'), 'duplicate' => __('Duplicate', 'ritmo-revenue'), 'delete' => __('Delete', 'ritmo-revenue')] as $ritmo_op => $ritmo_text) : ?>
                        <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-inline">
                            <?php wp_nonce_field('ritmo_relation_stage'); ?>
                            <input type="hidden" name="action" value="ritmo_relation_stage">
                            <input type="hidden" name="op" value="<?php echo esc_attr($ritmo_op); ?>">
                            <input type="hidden" name="id" value="<?php echo esc_attr($ritmo_relation['id']); ?>">
                            <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
                            <input type="hidden" name="tab" value="relations">
                            <button type="submit" class="button"><?php echo esc_html($ritmo_text); ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <?php if ($view['may_edit']) : ?>
        <?php if ($view['form'] === null) : ?>
            <form method="get" class="ritmo-card" data-ritmo="relation-add">
                <input type="hidden" name="page" value="<?php echo esc_attr($ritmo_page->slug); ?>">
                <input type="hidden" name="tab" value="relations">
                <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
                <fieldset>
                    <legend><?php esc_html_e('Add a relation: what should it do?', 'ritmo-revenue'); ?></legend>
                    <?php foreach ($view['types'] as $ritmo_i => $ritmo_type) : ?>
                        <label class="ritmo-choice"><input type="radio" name="add" value="<?php echo esc_attr($ritmo_type['value']); ?>" <?php checked($ritmo_i === 0); ?>> <?php echo esc_html($ritmo_type['label']); ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <button type="submit" class="button button-primary"><?php esc_html_e('Continue', 'ritmo-revenue'); ?></button>
            </form>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card ritmo-form" data-ritmo="relation-form">
                <?php wp_nonce_field('ritmo_relation_stage'); ?>
                <input type="hidden" name="action" value="ritmo_relation_stage">
                <input type="hidden" name="op" value="<?php echo esc_attr($view['form']['id'] === '' ? 'add' : 'edit'); ?>">
                <input type="hidden" name="id" value="<?php echo esc_attr($view['form']['id']); ?>">
                <input type="hidden" name="type" value="<?php echo esc_attr($view['form']['type']); ?>">
                <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
                <input type="hidden" name="tab" value="relations">
                <h2><?php echo esc_html($view['form']['type_label']); ?></h2>
                <?php foreach ($view['form']['nodes'] as $ritmo_node) : ?>
                    <?php View::render('admin/editors/field', ['node' => $ritmo_node]); ?>
                <?php endforeach; ?>
                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Keep in my draft', 'ritmo-revenue'); ?></button>
                    <a class="button" href="<?php echo esc_url($ritmo_page->url()); ?>"><?php esc_html_e('Cancel', 'ritmo-revenue'); ?></a>
                </p>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <section class="ritmo-card">
        <h2><?php esc_html_e('Price bands', 'ritmo-revenue'); ?></h2>
        <?php if ($view['bands'] === []) : ?>
            <p><?php esc_html_e('No price band: rooms are bound only by the floors and relations.', 'ritmo-revenue'); ?></p>
        <?php else : ?>
            <table class="widefat striped ritmo-table">
                <caption><?php esc_html_e('Lowest and highest price of a room', 'ritmo-revenue'); ?></caption>
                <thead><tr><th scope="col"><?php esc_html_e('Room', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Band', 'ritmo-revenue'); ?></th><th scope="col"><?php esc_html_e('Actions', 'ritmo-revenue'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($view['bands'] as $ritmo_band) : ?>
                        <tr data-ritmo="band-row">
                            <th scope="row" data-label="<?php esc_attr_e('Room', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_band['room']); ?></th>
                            <td data-label="<?php esc_attr_e('Band', 'ritmo-revenue'); ?>"><?php View::render('admin/editors/band', ['band' => $ritmo_band['band'], 'currency' => $ritmo_page->currency()]); ?></td>
                            <td data-label="<?php esc_attr_e('Actions', 'ritmo-revenue'); ?>">
                                <?php if ($view['may_edit']) : ?>
                                    <a class="button" href="<?php echo esc_url($ritmo_page->url(['band' => $ritmo_band['id']])); ?>"><?php esc_html_e('Edit', 'ritmo-revenue'); ?></a>
                                    <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-inline">
                                        <?php wp_nonce_field('ritmo_band_stage'); ?>
                                        <input type="hidden" name="action" value="ritmo_band_stage">
                                        <input type="hidden" name="op" value="delete">
                                        <input type="hidden" name="id" value="<?php echo esc_attr($ritmo_band['id']); ?>">
                                        <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
                                        <input type="hidden" name="tab" value="relations">
                                        <button type="submit" class="button"><?php esc_html_e('Delete', 'ritmo-revenue'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if ($view['may_edit']) : ?>
            <?php if ($view['band_form'] === null) : ?>
                <p><a class="button" href="<?php echo esc_url($ritmo_page->url(['band' => 'new'])); ?>"><?php esc_html_e('Add a price band', 'ritmo-revenue'); ?></a></p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-form">
                    <?php wp_nonce_field('ritmo_band_stage'); ?>
                    <input type="hidden" name="action" value="ritmo_band_stage">
                    <input type="hidden" name="op" value="save">
                    <input type="hidden" name="id" value="<?php echo esc_attr($view['band_form']['id']); ?>">
                    <input type="hidden" name="property" value="<?php echo esc_attr($ritmo_property); ?>">
                    <input type="hidden" name="tab" value="relations">
                    <?php foreach ($view['band_form']['nodes'] as $ritmo_node) : ?>
                        <?php View::render('admin/editors/field', ['node' => $ritmo_node]); ?>
                    <?php endforeach; ?>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e('Keep in my draft', 'ritmo-revenue'); ?></button> <a class="button" href="<?php echo esc_url($ritmo_page->url()); ?>"><?php esc_html_e('Cancel', 'ritmo-revenue'); ?></a></p>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
