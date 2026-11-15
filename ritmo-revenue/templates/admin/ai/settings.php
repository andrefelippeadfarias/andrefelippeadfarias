<?php
/**
 * Assistant › Settings: off by default; the customer's own Claude key (write-only, never shown),
 * the model, the effort, the server-side fallback, the monthly cap, the wait for an answer, the
 * price table of the estimate and the connection test.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<div class="ritmo-ai" data-ritmo="ai-settings">
    <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card ritmo-form" autocomplete="off">
        <?php wp_nonce_field('ritmo_ai_settings_save'); ?>
        <input type="hidden" name="action" value="ritmo_ai_settings_save">
        <input type="hidden" name="tab" value="settings">
        <h2><?php esc_html_e('Assistant settings', 'ritmo-revenue'); ?></h2>
        <p class="description"><?php esc_html_e('The assistant is optional and uses your own Claude API key: the cost of each answer is billed to your Anthropic account. The plugin works the same without it.', 'ritmo-revenue'); ?></p>
        <p>
            <label><input type="checkbox" name="enabled" value="1" data-ritmo="ai-enabled" <?php checked($view['enabled']); ?>> <?php esc_html_e('Turn the assistant on', 'ritmo-revenue'); ?></label>
        </p>
        <p>
            <strong><?php esc_html_e('Key:', 'ritmo-revenue'); ?></strong> <span data-ritmo="ai-key-status"><?php echo esc_html($view['key_saved']); ?></span>
        </p>
        <p>
            <label for="ritmo-ai-key"><?php echo esc_html($view['has_key'] ? __('Replace the Claude API key', 'ritmo-revenue') : __('Claude API key', 'ritmo-revenue')); ?></label>
            <input type="password" id="ritmo-ai-key" name="api_key" class="regular-text" autocomplete="new-password" spellcheck="false" data-ritmo="ai-key" aria-describedby="ritmo-ai-key-help">
            <span id="ritmo-ai-key-help" class="description"><?php esc_html_e('Leave empty to keep the saved key. A saved key is encrypted and never shown again.', 'ritmo-revenue'); ?></span>
        </p>
        <?php if ($view['vault_problem']) : ?>
            <p class="notice notice-error inline"><?php esc_html_e('The secrets vault cannot store keys now. See Settings, Diagnostics.', 'ritmo-revenue'); ?></p>
        <?php endif; ?>
        <p>
            <label for="ritmo-ai-model"><?php esc_html_e('Model', 'ritmo-revenue'); ?></label>
            <select id="ritmo-ai-model" name="model" data-ritmo="ai-model">
                <?php foreach ($view['models'] as $ritmo_model) : ?>
                    <option value="<?php echo esc_attr($ritmo_model['id']); ?>" <?php selected($ritmo_model['id'], $view['model']); ?> <?php disabled(!$ritmo_model['available']); ?>><?php echo esc_html($ritmo_model['available'] ? $ritmo_model['name'] : sprintf(/* translators: %s: name of a model */ __('%s (not available to this key)', 'ritmo-revenue'), $ritmo_model['name'])); ?></option>
                <?php endforeach; ?>
            </select>
            <span class="description"><?php echo esc_html($view['tested'] ? __('The list follows the last connection test.', 'ritmo-revenue') : __('Test the connection to see which models your key may use.', 'ritmo-revenue')); ?></span>
        </p>
        <?php if (!$view['model_available']) : ?>
            <p class="notice notice-error inline" data-ritmo="ai-model-unavailable"><?php esc_html_e('The saved key cannot use the chosen model. Choose another model.', 'ritmo-revenue'); ?></p>
        <?php endif; ?>
        <p>
            <label for="ritmo-ai-effort"><?php esc_html_e('Effort', 'ritmo-revenue'); ?></label>
            <select id="ritmo-ai-effort" name="effort" data-ritmo="ai-effort">
                <?php foreach ($view['efforts'] as $ritmo_effort) : ?>
                    <option value="<?php echo esc_attr($ritmo_effort['id']); ?>" <?php selected($ritmo_effort['id'], $view['effort']); ?>><?php echo esc_html($ritmo_effort['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label><input type="checkbox" name="fallback" value="1" data-ritmo="ai-fallback" <?php checked($view['fallback']); ?>> <?php esc_html_e('When the chosen model declines a request, let Claude answer with its fallback model', 'ritmo-revenue'); ?></label>
        </p>
        <p>
            <label for="ritmo-ai-cap"><?php esc_html_e('Monthly spending cap (US dollars)', 'ritmo-revenue'); ?></label>
            <input type="number" id="ritmo-ai-cap" name="monthly_cap_usd" min="1" max="10000" step="0.01" value="<?php echo esc_attr((string) $view['cap']); ?>" data-ritmo="ai-cap" aria-describedby="ritmo-ai-cap-help">
            <span id="ritmo-ai-cap-help" class="description"><?php esc_html_e('When the estimated spend of the month reaches the cap, the assistant stays off until the next month.', 'ritmo-revenue'); ?></span>
        </p>
        <p>
            <label for="ritmo-ai-timeout"><?php esc_html_e('Wait for an answer (seconds)', 'ritmo-revenue'); ?></label>
            <input type="number" id="ritmo-ai-timeout" name="timeout_seconds" min="15" max="300" step="1" value="<?php echo esc_attr((string) $view['timeout']); ?>">
        </p>
        <details>
            <summary><?php esc_html_e('Prices used for the estimate (US dollars per million tokens)', 'ritmo-revenue'); ?></summary>
            <div class="ritmo-ai-table-wrap">
                <table class="widefat striped ritmo-ai-prices">
                    <caption class="screen-reader-text"><?php esc_html_e('Prices per million tokens', 'ritmo-revenue'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Model', 'ritmo-revenue'); ?></th>
                            <th scope="col"><?php esc_html_e('Input', 'ritmo-revenue'); ?></th>
                            <th scope="col"><?php esc_html_e('Output', 'ritmo-revenue'); ?></th>
                            <th scope="col"><?php esc_html_e('Cache read', 'ritmo-revenue'); ?></th>
                            <th scope="col"><?php esc_html_e('Cache write', 'ritmo-revenue'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($view['prices'] as $ritmo_row) : ?>
                            <tr>
                                <th scope="row"><?php echo esc_html($ritmo_row['name']); ?></th>
                                <?php foreach (['input', 'output', 'cache_read', 'cache_write'] as $ritmo_kind) : ?>
                                    <td><input type="number" min="0" max="1000" step="0.001" name="<?php echo esc_attr('price_' . str_replace(['-', '.'], '_', $ritmo_row['model']) . '_' . $ritmo_kind); ?>" value="<?php echo esc_attr((string) $ritmo_row[$ritmo_kind]); ?>" aria-label="<?php echo esc_attr($ritmo_row['name'] . ' · ' . $ritmo_kind); ?>"></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <p><button type="submit" class="button button-primary" data-ritmo="ai-settings-save"><?php esc_html_e('Save the assistant settings', 'ritmo-revenue'); ?></button></p>
    </form>

    <section class="ritmo-card" aria-labelledby="ritmo-ai-test-title">
        <h2 id="ritmo-ai-test-title"><?php esc_html_e('Test the connection', 'ritmo-revenue'); ?></h2>
        <p class="description"><?php esc_html_e('Lists the models your key may use. It needs the assistant on and a saved key; it costs nothing.', 'ritmo-revenue'); ?></p>
        <form method="post" action="<?php echo esc_url($view['post_url']); ?>">
            <?php wp_nonce_field('ritmo_ai_test'); ?>
            <input type="hidden" name="action" value="ritmo_ai_test">
            <input type="hidden" name="tab" value="settings">
            <button type="submit" class="button" data-ritmo="ai-test"><?php esc_html_e('Test now', 'ritmo-revenue'); ?></button>
        </form>
        <?php if ($view['has_key']) : ?>
            <details>
                <summary><?php esc_html_e('Remove the key', 'ritmo-revenue'); ?></summary>
                <form method="post" action="<?php echo esc_url($view['post_url']); ?>">
                    <?php wp_nonce_field('ritmo_ai_key_forget'); ?>
                    <input type="hidden" name="action" value="ritmo_ai_key_forget">
                    <input type="hidden" name="tab" value="settings">
                    <button type="submit" class="button button-link-delete" data-ritmo="ai-key-forget"><?php esc_html_e('Remove the key', 'ritmo-revenue'); ?></button>
                </form>
            </details>
        <?php endif; ?>
    </section>
</div>
