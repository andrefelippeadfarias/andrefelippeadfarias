<?php
/**
 * Assistant › Ask: a request in plain words becomes a draft for a person (an owner order for the
 * order form or rule changes for the review of the rules), the drafts waiting, and the weekly
 * report in words. The assistant never writes a price and never saves anything.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$ritmo_page = $view['page'];
?>
<div class="ritmo-ai" data-ritmo="ai-ask" data-rest-url="<?php echo esc_url($view['rest_url']); ?>" data-rest-nonce="<?php echo esc_attr($view['rest_nonce']); ?>" data-busy-text="<?php esc_attr_e('The assistant is preparing the draft; this can take up to two minutes…', 'ritmo-revenue'); ?>" data-error-text="<?php esc_attr_e('The assistant could not answer now.', 'ritmo-revenue'); ?>">
    <?php if (!$view['ready']) : ?>
        <div class="notice notice-warning inline" data-ritmo="ai-blocked">
            <p><?php echo esc_html($view['blocker']); ?>
                <?php if ($view['may_settings']) : ?>
                    <a href="<?php echo esc_url($view['settings_url']); ?>"><?php esc_html_e('Open the assistant settings', 'ritmo-revenue'); ?></a>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <section class="ritmo-card" aria-labelledby="ritmo-ai-ask-title">
        <h2 id="ritmo-ai-ask-title"><?php esc_html_e('Ask for a draft', 'ritmo-revenue'); ?></h2>
        <p class="description"><?php esc_html_e('Write what you want in your own words. The assistant only prepares a draft: you open it in the order form or in the review of the rules, check it and decide. It never changes prices, modes, safety limits or the emergency switch.', 'ritmo-revenue'); ?></p>
        <form method="post" action="<?php echo esc_url($view['post_url']); ?>" data-ritmo-js="ai-draft-form" autocomplete="off">
            <?php wp_nonce_field('ritmo_ai_draft'); ?>
            <input type="hidden" name="action" value="ritmo_ai_draft">
            <input type="hidden" name="tab" value="ask">
            <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
            <p>
                <label for="ritmo-ai-request"><?php esc_html_e('Your request', 'ritmo-revenue'); ?></label>
                <textarea id="ritmo-ai-request" name="request" rows="3" maxlength="1000" class="large-text" required aria-describedby="ritmo-ai-request-help" data-ritmo="ai-request"><?php echo esc_html($view['request']); ?></textarea>
                <span id="ritmo-ai-request-help" class="description"><?php esc_html_e('Do not write guest names, e-mails, phone numbers or document numbers. Refer to a booking by its id.', 'ritmo-revenue'); ?></span>
            </p>
            <details>
                <summary><?php esc_html_e('Examples', 'ritmo-revenue'); ?></summary>
                <ul>
                    <?php foreach ($view['examples'] as $ritmo_example) : ?>
                        <li><?php echo esc_html($ritmo_example); ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
            <p>
                <button type="submit" class="button button-primary" data-ritmo="ai-draft-submit" <?php disabled(!$view['ready']); ?>><?php esc_html_e('Prepare the draft', 'ritmo-revenue'); ?></button>
                <span class="spinner" aria-hidden="true"></span>
            </p>
            <div role="status" aria-live="polite" data-ritmo="ai-draft-status"></div>
        </form>
    </section>

    <section class="ritmo-card" aria-labelledby="ritmo-ai-drafts-title" data-ritmo="ai-drafts">
        <h2 id="ritmo-ai-drafts-title"><?php esc_html_e('Drafts waiting for you', 'ritmo-revenue'); ?></h2>
        <?php if ($view['drafts'] === []) : ?>
            <p data-ritmo="ai-no-drafts"><?php esc_html_e('No draft is waiting.', 'ritmo-revenue'); ?></p>
        <?php endif; ?>
        <?php foreach ($view['drafts'] as $ritmo_draft) : ?>
            <article class="ritmo-ai-draft" data-ritmo="ai-draft" data-kind="<?php echo esc_attr($ritmo_draft['kind']); ?>">
                <h3><?php echo esc_html($ritmo_draft['kind_text']); ?></h3>
                <p><strong><?php echo esc_html($ritmo_draft['summary']); ?></strong></p>
                <p class="description"><?php /* translators: %s: the request as the person wrote it */ echo esc_html(sprintf(__('You asked: %s', 'ritmo-revenue'), $ritmo_draft['request'])); ?></p>
                <ul>
                    <?php foreach ($ritmo_draft['lines'] as $ritmo_line) : ?>
                        <li><?php echo esc_html($ritmo_line); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($ritmo_draft['questions'] !== []) : ?>
                    <p><?php esc_html_e('The assistant asks:', 'ritmo-revenue'); ?></p>
                    <ul>
                        <?php foreach ($ritmo_draft['questions'] as $ritmo_question) : ?>
                            <li><?php echo esc_html($ritmo_question); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div class="ritmo-ai-actions">
                    <?php if ($view['may_open']) : ?>
                        <form method="post" action="<?php echo esc_url($view['post_url']); ?>">
                            <?php wp_nonce_field('ritmo_ai_draft_open:' . $ritmo_draft['id']); ?>
                            <input type="hidden" name="action" value="ritmo_ai_draft_open">
                            <input type="hidden" name="draft" value="<?php echo esc_attr($ritmo_draft['id']); ?>">
                            <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
                            <button type="submit" class="button button-primary" data-ritmo="ai-draft-open"><?php echo esc_html($ritmo_draft['kind'] === 'order' ? __('Open in the order form', 'ritmo-revenue') : __('Put in my rules draft', 'ritmo-revenue')); ?></button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url($view['post_url']); ?>">
                        <?php wp_nonce_field('ritmo_ai_draft_discard:' . $ritmo_draft['id']); ?>
                        <input type="hidden" name="action" value="ritmo_ai_draft_discard">
                        <input type="hidden" name="draft" value="<?php echo esc_attr($ritmo_draft['id']); ?>">
                        <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
                        <input type="hidden" name="tab" value="ask">
                        <button type="submit" class="button" data-ritmo="ai-draft-discard"><?php esc_html_e('Discard', 'ritmo-revenue'); ?></button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="ritmo-card" aria-labelledby="ritmo-ai-weekly-title">
        <h2 id="ritmo-ai-weekly-title"><?php esc_html_e('This week in words', 'ritmo-revenue'); ?></h2>
        <p class="description"><?php esc_html_e('The assistant words the numbers of the weekly report. It may only cite numbers the plugin computed; a text with any other number is thrown away.', 'ritmo-revenue'); ?></p>
        <?php if ($view['result'] !== null) : ?>
            <div class="ritmo-ai-text" data-ritmo="ai-result">
                <?php foreach (preg_split('/\n\s*\n/', $view['result']['text']) ?: [] as $ritmo_paragraph) : ?>
                    <p><?php echo esc_html($ritmo_paragraph); ?></p>
                <?php endforeach; ?>
                <p class="description"><?php echo esc_html($view['result']['fallback'] ? __('Written by the fallback model after the chosen model declined.', 'ritmo-revenue') : __('Written by the assistant from the plugin\'s numbers.', 'ritmo-revenue')); ?></p>
            </div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url($view['post_url']); ?>">
            <?php wp_nonce_field('ritmo_ai_weekly'); ?>
            <input type="hidden" name="action" value="ritmo_ai_weekly">
            <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
            <input type="hidden" name="tab" value="ask">
            <button type="submit" class="button" data-ritmo="ai-weekly" <?php disabled(!$view['ready']); ?>><?php esc_html_e('Write this week\'s report', 'ritmo-revenue'); ?></button>
        </form>
    </section>
</div>
