<?php
/**
 * A button that asks the assistant to put an alert or a run report in plain words, for the Alerts
 * screen (an alert's detail) and the run reports. Shown only to whoever may use the assistant
 * (AiScreens::explainView); the answer is added as text by assets/ai.js. Without the script the
 * button is not shown.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<div class="ritmo-ai-explain" data-ritmo="ai-explain" hidden>
    <button type="button" class="button" data-ritmo-js="ai-explain" data-rest-url="<?php echo esc_url($view['rest_url']); ?>" data-rest-nonce="<?php echo esc_attr($view['rest_nonce']); ?>" data-busy-text="<?php esc_attr_e('The assistant is writing; this can take up to two minutes…', 'ritmo-revenue'); ?>" data-error-text="<?php esc_attr_e('The assistant could not answer now.', 'ritmo-revenue'); ?>" data-action-label="<?php esc_attr_e('Next step:', 'ritmo-revenue'); ?>"><?php echo esc_html($view['label']); ?></button>
    <div class="ritmo-ai-text" role="status" aria-live="polite" data-ritmo="ai-explain-result"></div>
</div>
