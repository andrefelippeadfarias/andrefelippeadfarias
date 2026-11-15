<?php
/**
 * Assistant › Usage and cost: the month's estimated spend against the cap, the tokens and the last
 * calls (what for, which model, tokens, cost, fallback), never what was asked or answered.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<div class="ritmo-ai" data-ritmo="ai-usage">
    <section class="ritmo-card" aria-labelledby="ritmo-ai-month-title">
        <h2 id="ritmo-ai-month-title"><?php /* translators: %s: a month such as November 2026 */ echo esc_html(sprintf(__('Estimated spend in %s', 'ritmo-revenue'), $view['month'])); ?></h2>
        <p class="ritmo-ai-spend" data-ritmo="ai-spent"><?php /* translators: 1: amount spent, 2: monthly cap */ echo esc_html(sprintf(__('%1$s of a cap of %2$s', 'ritmo-revenue'), $view['spent'], $view['cap'])); ?></p>
        <div class="ritmo-ai-meter" role="img" aria-label="<?php /* translators: %d: share of the cap used */ echo esc_attr(sprintf(__('%d%% of the cap used', 'ritmo-revenue'), $view['share'])); ?>"><span style="<?php echo esc_attr('width: ' . (int) $view['share'] . '%'); ?>"></span></div>
        <?php if ($view['cap_reached']) : ?>
            <p class="notice notice-warning inline" data-ritmo="ai-cap-reached"><?php esc_html_e('The cap is reached: the assistant stays off until next month, or until the cap is raised.', 'ritmo-revenue'); ?></p>
        <?php endif; ?>
        <p class="description"><?php esc_html_e('An estimate from the price table in the settings. Your Anthropic invoice is the reference.', 'ritmo-revenue'); ?></p>
        <dl class="ritmo-ai-totals">
            <dt><?php esc_html_e('Answers', 'ritmo-revenue'); ?></dt><dd><?php echo esc_html($view['totals']['calls']); ?></dd>
            <dt><?php esc_html_e('Input tokens (of them read from the cache)', 'ritmo-revenue'); ?></dt><dd><?php echo esc_html($view['totals']['input'] . ' (' . $view['totals']['cached'] . ')'); ?></dd>
            <dt><?php esc_html_e('Output tokens', 'ritmo-revenue'); ?></dt><dd><?php echo esc_html($view['totals']['output']); ?></dd>
            <dt><?php esc_html_e('Answered by the fallback model', 'ritmo-revenue'); ?></dt><dd><?php echo esc_html($view['totals']['fallbacks']); ?></dd>
            <dt><?php esc_html_e('Declined by the model', 'ritmo-revenue'); ?></dt><dd><?php echo esc_html($view['totals']['refusals']); ?></dd>
        </dl>
        <?php if ($view['previous'] !== null) : ?>
            <p class="description"><?php /* translators: 1: month, 2: amount, 3: number of answers */ echo esc_html(sprintf(__('In %1$s: %2$s in %3$s answers.', 'ritmo-revenue'), $view['previous']['month'], $view['previous']['spent'], $view['previous']['calls'])); ?></p>
        <?php endif; ?>
    </section>

    <section class="ritmo-card" aria-labelledby="ritmo-ai-calls-title">
        <h2 id="ritmo-ai-calls-title"><?php esc_html_e('Last answers', 'ritmo-revenue'); ?></h2>
        <?php if ($view['calls'] === []) : ?>
            <p><?php esc_html_e('No answer this month.', 'ritmo-revenue'); ?></p>
        <?php else : ?>
            <div class="ritmo-ai-table-wrap">
                <table class="widefat striped" data-ritmo="ai-calls">
                    <caption class="screen-reader-text"><?php esc_html_e('Last answers of the month', 'ritmo-revenue'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('When', 'ritmo-revenue'); ?></th>
                            <th scope="col"><?php esc_html_e('What for', 'ritmo-revenue'); ?></th>
                            <th scope="col"><?php esc_html_e('Model', 'ritmo-revenue'); ?></th>
                            <th scope="col"><?php esc_html_e('Tokens in / out', 'ritmo-revenue'); ?></th>
                            <th scope="col"><?php esc_html_e('Estimated cost', 'ritmo-revenue'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($view['calls'] as $ritmo_call) : ?>
                            <tr>
                                <td><?php echo esc_html(str_replace(['T', 'Z'], [' ', ''], $ritmo_call['at'])); ?></td>
                                <td><?php echo esc_html($ritmo_call['purpose']); ?></td>
                                <td><?php echo esc_html($ritmo_call['fallback'] ? $ritmo_call['model'] . ' · ' . __('fallback', 'ritmo-revenue') : $ritmo_call['model']); ?></td>
                                <td><?php echo esc_html($ritmo_call['tokens']); ?></td>
                                <td><?php echo esc_html($ritmo_call['cost']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
