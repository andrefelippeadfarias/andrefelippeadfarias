<?php
/**
 * The run reports: each run's lines in the reader's language; runs recorded before translation
 * was available show their lines as recorded.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<?php if ($view['runs'] === []) : ?>
    <div class="ritmo-empty" data-ritmo="empty"><p><?php esc_html_e('No run yet. The first scheduled run writes its report here.', 'ritmo-revenue'); ?></p></div>
<?php else : ?>
    <ol class="ritmo-runs" data-ritmo="run-reports">
        <?php foreach ($view['runs'] as $ritmo_run) : ?>
            <li>
                <p class="ritmo-run-when"><strong><?php echo esc_html($ritmo_run['started']); ?></strong></p>
                <?php if ($ritmo_run['lines'] === []) : ?>
                    <p><?php esc_html_e('This run left no report lines.', 'ritmo-revenue'); ?></p>
                <?php else : ?>
                    <ul>
                        <?php foreach ($ritmo_run['lines'] as $ritmo_line) : ?>
                            <li><?php echo esc_html($ritmo_line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($ritmo_run['recorded']) : ?>
                        <p class="description"><?php esc_html_e('(recorded before translation was available)', 'ritmo-revenue'); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>
