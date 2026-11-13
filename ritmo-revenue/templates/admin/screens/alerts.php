<?php
/**
 * Alerts: filters, the opened alert (what happened, facts, what to do, history, actions) and the
 * list; the user's own e-mail preference.
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
$ritmo_f = $view['filter'];
$ritmo_d = $view['detail'];
?>
<details class="ritmo-filters">
    <summary><?php esc_html_e('Filters', 'ritmo-revenue'); ?></summary>
    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
        <input type="hidden" name="page" value="ritmo-revenue-alerts">
        <input type="hidden" name="property" value="<?php echo esc_attr($view['property_id']); ?>">
        <p><label for="ritmo-a-sev"><?php esc_html_e('Severity', 'ritmo-revenue'); ?></label>
            <select id="ritmo-a-sev" name="severity"><option value=""><?php esc_html_e('any', 'ritmo-revenue'); ?></option>
                <?php foreach ($view['severities'] as $ritmo_value => $ritmo_label) : ?><option value="<?php echo esc_attr($ritmo_value); ?>" <?php selected($ritmo_value, $ritmo_f['severity'] ?? ''); ?>><?php echo esc_html($ritmo_label); ?></option><?php endforeach; ?>
            </select></p>
        <p><label for="ritmo-a-status"><?php esc_html_e('Status', 'ritmo-revenue'); ?></label>
            <select id="ritmo-a-status" name="status">
                <option value="unresolved" <?php selected('unresolved', $ritmo_f['status_value']); ?>><?php esc_html_e('not resolved', 'ritmo-revenue'); ?></option>
                <option value="open" <?php selected('open', $ritmo_f['status_value']); ?>><?php esc_html_e('open', 'ritmo-revenue'); ?></option>
                <option value="acknowledged" <?php selected('acknowledged', $ritmo_f['status_value']); ?>><?php esc_html_e('acknowledged', 'ritmo-revenue'); ?></option>
                <option value="resolved" <?php selected('resolved', $ritmo_f['status_value']); ?>><?php esc_html_e('resolved', 'ritmo-revenue'); ?></option>
                <option value="all" <?php selected('all', $ritmo_f['status_value']); ?>><?php esc_html_e('all', 'ritmo-revenue'); ?></option>
            </select></p>
        <p><label for="ritmo-a-group"><?php esc_html_e('Kind', 'ritmo-revenue'); ?></label>
            <select id="ritmo-a-group" name="group"><option value=""><?php esc_html_e('any', 'ritmo-revenue'); ?></option>
                <?php foreach ($view['groups'] as $ritmo_value => $ritmo_label) : ?><option value="<?php echo esc_attr($ritmo_value); ?>" <?php selected($ritmo_value, $ritmo_f['group'] ?? ''); ?>><?php echo esc_html($ritmo_label); ?></option><?php endforeach; ?>
            </select></p>
        <p><label for="ritmo-a-from"><?php esc_html_e('First seen from', 'ritmo-revenue'); ?></label> <input type="date" id="ritmo-a-from" name="from" value="<?php echo esc_attr($ritmo_f['from'] ?? ''); ?>">
            <label for="ritmo-a-to"><?php esc_html_e('to', 'ritmo-revenue'); ?></label> <input type="date" id="ritmo-a-to" name="to" value="<?php echo esc_attr($ritmo_f['to'] ?? ''); ?>"></p>
        <p class="ritmo-actions"><button type="submit" class="button"><?php esc_html_e('Apply', 'ritmo-revenue'); ?></button> <a href="<?php echo esc_url($view['clear']); ?>"><?php esc_html_e('Clear', 'ritmo-revenue'); ?></a></p>
    </form>
</details>

<?php if ($ritmo_d !== null) : ?>
    <section id="ritmo-alert-detail" class="ritmo-card ritmo-wide" data-ritmo="alert-detail">
        <h2 tabindex="-1" data-ritmo-focus><span class="ritmo-badge ritmo-tone-<?php echo esc_attr($ritmo_d['tone']); ?>"><?php echo esc_html($ritmo_d['severity']); ?></span> <?php echo esc_html($ritmo_d['title']); ?></h2>
        <h3><?php esc_html_e('What happened', 'ritmo-revenue'); ?></h3>
        <p><?php echo esc_html($ritmo_d['explain']); ?></p>
        <?php if ($ritmo_d['facts'] !== []) : ?>
            <dl class="ritmo-facts"><?php foreach ($ritmo_d['facts'] as $ritmo_fact) : ?><dt><?php echo esc_html($ritmo_fact['label']); ?></dt><dd><?php echo esc_html($ritmo_fact['value']); ?></dd><?php endforeach; ?></dl>
        <?php endif; ?>
        <?php if ($ritmo_d['night_url'] !== '') : ?><p><a href="<?php echo esc_url($ritmo_d['night_url']); ?>"><?php esc_html_e('Open the night', 'ritmo-revenue'); ?></a></p><?php endif; ?>
        <h3><?php esc_html_e('What to do', 'ritmo-revenue'); ?></h3>
        <p><?php echo esc_html($ritmo_d['action']); ?></p>
        <h3><?php esc_html_e('History', 'ritmo-revenue'); ?></h3>
        <ul class="ritmo-list"><?php foreach ($ritmo_d['history'] as $ritmo_event) : ?><li><span class="ritmo-muted"><?php echo esc_html($ritmo_event['when']); ?></span> · <?php echo esc_html($ritmo_event['text']); ?></li><?php endforeach; ?></ul>
        <div class="ritmo-actions">
            <?php if ($ritmo_d['can_ack']) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ritmo-inline">
                    <?php wp_nonce_field('ritmo_alert_acknowledge:' . $ritmo_d['id']); ?>
                    <input type="hidden" name="action" value="ritmo_alert_acknowledge">
                    <input type="hidden" name="alert_id" value="<?php echo esc_attr((string) $ritmo_d['id']); ?>">
                    <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
                    <button type="submit" class="button" data-ritmo="alert-ack"><?php esc_html_e('Acknowledge', 'ritmo-revenue'); ?></button>
                </form>
            <?php endif; ?>
            <?php if ($ritmo_d['can_resolve']) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ritmo-inline">
                    <?php wp_nonce_field('ritmo_alert_resolve:' . $ritmo_d['id']); ?>
                    <input type="hidden" name="action" value="ritmo_alert_resolve">
                    <input type="hidden" name="alert_id" value="<?php echo esc_attr((string) $ritmo_d['id']); ?>">
                    <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
                    <button type="submit" class="button" data-ritmo="alert-resolve"><?php esc_html_e('Resolve by hand', 'ritmo-revenue'); ?></button>
                </form>
            <?php endif; ?>
        </div>
        <?php if ($ritmo_d['wave'] && $view['hold'] && $view['can_approve']) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ritmo="wave-release">
                <?php wp_nonce_field('ritmo_wave_release:' . $view['property_id']); ?>
                <input type="hidden" name="action" value="ritmo_wave_release">
                <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
                <p><label><input type="checkbox" name="checked" value="1" required> <?php esc_html_e('I checked these cancellations are legitimate.', 'ritmo-revenue'); ?></label></p>
                <button type="submit" class="button"><?php esc_html_e('Release the hold of price cuts', 'ritmo-revenue'); ?></button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="ritmo-card ritmo-wide">
    <?php if ($view['rows'] === []) : ?>
        <p data-ritmo="empty"><?php esc_html_e('No open alert. The plugin checks bookings, the calendar and its own writes in every run.', 'ritmo-revenue'); ?></p>
    <?php else : ?>
        <table class="widefat striped ritmo-table">
            <caption><?php echo esc_html($view['paging']['label']); ?></caption>
            <thead><tr>
                <th scope="col"><?php esc_html_e('Alert', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Severity', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Room and night', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('First seen', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Last seen', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Times', 'ritmo-revenue'); ?></th>
                <th scope="col"><?php esc_html_e('Status', 'ritmo-revenue'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($view['rows'] as $ritmo_row) : ?>
                <tr data-ritmo="alert-row">
                    <th scope="row" data-label="<?php esc_attr_e('Alert', 'ritmo-revenue'); ?>"><a href="<?php echo esc_url($ritmo_row['url']); ?>"><?php echo esc_html($ritmo_row['title']); ?></a></th>
                    <td data-label="<?php esc_attr_e('Severity', 'ritmo-revenue'); ?>"><span class="ritmo-badge ritmo-tone-<?php echo esc_attr($ritmo_row['tone']); ?>"><?php echo esc_html($ritmo_row['severity']); ?></span></td>
                    <td data-label="<?php esc_attr_e('Room and night', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['where']); ?></td>
                    <td data-label="<?php esc_attr_e('First seen', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['first']); ?></td>
                    <td data-label="<?php esc_attr_e('Last seen', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['last']); ?></td>
                    <td data-label="<?php esc_attr_e('Times', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['occurrences']); ?></td>
                    <td data-label="<?php esc_attr_e('Status', 'ritmo-revenue'); ?>"><?php echo esc_html($ritmo_row['status']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="ritmo-actions">
            <?php if ($view['paging']['previous'] !== '') : ?><a href="<?php echo esc_url($view['paging']['previous']); ?>"><?php esc_html_e('Previous', 'ritmo-revenue'); ?></a><?php endif; ?>
            <?php if ($view['paging']['next'] !== '') : ?><a href="<?php echo esc_url($view['paging']['next']); ?>"><?php esc_html_e('Next', 'ritmo-revenue'); ?></a><?php endif; ?>
        </p>
    <?php endif; ?>
</section>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ritmo-card" data-ritmo="alert-email-pref">
    <?php wp_nonce_field('ritmo_alert_email_pref'); ?>
    <input type="hidden" name="action" value="ritmo_alert_email_pref">
    <input type="hidden" name="property_id" value="<?php echo esc_attr($view['property_id']); ?>">
    <p><label><input type="checkbox" name="email" value="1" <?php checked($view['email']); ?>> <?php esc_html_e('E-mail me critical alerts', 'ritmo-revenue'); ?></label></p>
    <button type="submit" class="button"><?php esc_html_e('Save my preference', 'ritmo-revenue'); ?></button>
</form>
