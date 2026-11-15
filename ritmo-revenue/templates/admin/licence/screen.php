<?php
/**
 * Settings › Licence: the standing of the licence and what it means, the facts of the last check,
 * the key (write-only: never printed back), "Check now", removing the key, and the update channel.
 *
 * @var array<string, mixed> $view LicenceScreen::view()
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<section class="ritmo-card ritmo-wide" data-ritmo="licence-status">
    <h2><?php esc_html_e('Licence', 'ritmo-revenue'); ?>
        <span class="ritmo-badge ritmo-tone-<?php echo esc_attr($view['tone']); ?>" data-ritmo="licence-standing" data-standing="<?php echo esc_attr($view['standing']); ?>"><?php echo esc_html($view['standing_text']); ?></span>
    </h2>
    <p data-ritmo="licence-reason"><?php echo esc_html($view['reason']); ?></p>
    <p data-ritmo="licence-consequence"><?php echo esc_html($view['consequence']); ?></p>
    <dl class="ritmo-facts">
        <dt><?php esc_html_e('Valid until', 'ritmo-revenue'); ?></dt>
        <dd data-ritmo="licence-expires"><?php echo esc_html($view['expires']); ?></dd>
        <dt><?php esc_html_e('Last answer of the licence server', 'ritmo-revenue'); ?></dt>
        <dd data-ritmo="licence-last-answer"><?php echo esc_html($view['last_answer']); ?></dd>
        <dt><?php esc_html_e('Last check', 'ritmo-revenue'); ?></dt>
        <dd data-ritmo="licence-last-check"><?php echo esc_html($view['last_attempt']); ?> — <?php echo esc_html($view['last_outcome']); ?></dd>
        <?php if ($view['grace_ends'] !== '') : ?>
            <dt><?php esc_html_e('Grace period ends', 'ritmo-revenue'); ?></dt>
            <dd data-ritmo="licence-grace-ends"><?php echo esc_html($view['grace_ends']); ?></dd>
        <?php endif; ?>
        <dt><?php esc_html_e('Grace period', 'ritmo-revenue'); ?></dt>
        <dd><?php /* translators: %d: number of days */ echo esc_html(sprintf(_n('%d day', '%d days', (int) $view['grace_days'], 'ritmo-revenue'), (int) $view['grace_days'])); ?></dd>
        <dt><?php esc_html_e('Checked by', 'ritmo-revenue'); ?></dt>
        <dd data-ritmo="licence-provider"><?php echo esc_html($view['provider']); ?></dd>
        <?php if ($view['message'] !== '') : ?>
            <dt><?php esc_html_e('Message of the licence server', 'ritmo-revenue'); ?></dt>
            <dd data-ritmo="licence-message"><?php echo esc_html($view['message']); ?></dd>
        <?php endif; ?>
    </dl>
    <p class="ritmo-hint"><?php esc_html_e('The licence is checked once a day and when you press "Check now", never during a pricing run. If the licence server cannot be reached, the last valid answer is kept for the grace period.', 'ritmo-revenue'); ?></p>
    <?php if ($view['server_invalid']) : ?>
        <p class="ritmo-warning" data-ritmo="licence-server-invalid"><?php esc_html_e('RITMO_LICENCE_SERVER_URL in wp-config.php is not used: it only counts for development, on a local site, with a valid address and the public key of that server. The licence is checked with the plugin\'s own licence server.', 'ritmo-revenue'); ?></p>
    <?php endif; ?>
    <?php if ($view['dev_mode']) : ?>
        <p class="ritmo-warning" data-ritmo="licence-dev-mode"><?php esc_html_e('Development mode is on (RITMO_DEV and RITMO_LICENCE_DEV on a local site): test keys starting with TEST- are accepted.', 'ritmo-revenue'); ?></p>
    <?php endif; ?>
    <?php if ($view['key_saved']) : ?>
        <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-inline">
            <?php wp_nonce_field('ritmo_licence_check'); ?>
            <input type="hidden" name="action" value="ritmo_licence_check">
            <input type="hidden" name="tab" value="licence">
            <button type="submit" class="button" data-ritmo="licence-check"><?php esc_html_e('Check now', 'ritmo-revenue'); ?></button>
        </form>
    <?php endif; ?>
</section>

<form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-card ritmo-form" autocomplete="off" data-ritmo="licence-key-form">
    <?php wp_nonce_field('ritmo_licence_save'); ?>
    <input type="hidden" name="action" value="ritmo_licence_save">
    <input type="hidden" name="tab" value="licence">
    <h2><?php esc_html_e('Licence key', 'ritmo-revenue'); ?></h2>
    <p data-ritmo="licence-key-state"><?php echo esc_html($view['key_saved'] ? sprintf(/* translators: %s: date and time the key was saved */ __('A key is saved (since %s). It is never shown again.', 'ritmo-revenue'), $view['key_saved_at']) : __('No key is saved.', 'ritmo-revenue')); ?></p>
    <p><label for="ritmo-licence-key"><?php echo esc_html($view['key_saved'] ? __('Replace the licence key', 'ritmo-revenue') : __('Licence key', 'ritmo-revenue')); ?></label>
        <input type="password" id="ritmo-licence-key" name="licence_key" class="regular-text" autocomplete="new-password" spellcheck="false" required data-ritmo="licence-key"></p>
    <button type="submit" class="button button-primary" data-ritmo="licence-save"><?php esc_html_e('Save and activate', 'ritmo-revenue'); ?></button>
</form>

<?php if ($view['key_saved']) : ?>
    <section class="ritmo-card">
        <details>
            <summary><?php esc_html_e('Remove the licence key', 'ritmo-revenue'); ?></summary>
            <form method="post" action="<?php echo esc_url($view['post_url']); ?>" data-ritmo="licence-remove-form">
                <?php wp_nonce_field('ritmo_licence_remove'); ?>
                <input type="hidden" name="action" value="ritmo_licence_remove">
                <input type="hidden" name="tab" value="licence">
                <p><?php esc_html_e('The key is freed on the licence server so it can be used on another site. Without a licence, every property goes back to shadow mode at the start of its next run.', 'ritmo-revenue'); ?></p>
                <p><label><input type="checkbox" name="confirm" value="1" required data-ritmo="licence-remove-confirm"> <?php esc_html_e('I understand', 'ritmo-revenue'); ?></label></p>
                <button type="submit" class="button" data-ritmo="licence-remove"><?php esc_html_e('Remove the key', 'ritmo-revenue'); ?></button>
            </form>
        </details>
    </section>
<?php endif; ?>

<section class="ritmo-card" data-ritmo="licence-updates">
    <h2><?php esc_html_e('Updates', 'ritmo-revenue'); ?></h2>
    <p><?php /* translators: %s: version number */ echo esc_html(sprintf(__('Installed version: %s', 'ritmo-revenue'), $view['installed_version'])); ?></p>
    <?php if (!$view['updates_on']) : ?>
        <p data-ritmo="update-state"><?php esc_html_e('The update channel is not configured on this site: updates are installed by hand.', 'ritmo-revenue'); ?></p>
    <?php elseif ($view['update_version'] !== '') : ?>
        <p data-ritmo="update-state"><?php /* translators: %s: version number */ echo esc_html(sprintf(__('Version %s is available, signed by the publisher.', 'ritmo-revenue'), $view['update_version'])); ?>
            <a href="<?php echo esc_url($view['update_url']); ?>"><?php esc_html_e('Go to the updates', 'ritmo-revenue'); ?></a></p>
    <?php else : ?>
        <p data-ritmo="update-state"><?php esc_html_e('No newer signed version is known.', 'ritmo-revenue'); ?></p>
    <?php endif; ?>
    <?php if ($view['update_problem'] !== '') : ?>
        <p class="ritmo-warning" data-ritmo="update-problem"><?php echo esc_html($view['update_problem']); ?></p>
    <?php endif; ?>
    <?php if ($view['updates_on']) : ?>
        <form method="post" action="<?php echo esc_url($view['post_url']); ?>" class="ritmo-inline">
            <?php wp_nonce_field('ritmo_update_check'); ?>
            <input type="hidden" name="action" value="ritmo_update_check">
            <input type="hidden" name="tab" value="licence">
            <button type="submit" class="button" data-ritmo="update-check"><?php esc_html_e('Check for updates', 'ritmo-revenue'); ?></button>
        </form>
    <?php endif; ?>
    <p class="ritmo-hint"><?php esc_html_e('An update is installed only when its information and its package carry a valid signature of the publisher, the package comes from the update server and the version is newer.', 'ritmo-revenue'); ?></p>
</section>
