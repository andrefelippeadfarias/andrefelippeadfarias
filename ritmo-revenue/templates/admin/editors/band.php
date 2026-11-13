<?php
/**
 * One price band in words: lowest – highest (money and price plan), nights and horizon.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

use Ritmo\Admin\Components\Money;
use Ritmo\Admin\Editors\ChoiceLabels;

defined('ABSPATH') || exit;

$ritmo_band = $view['band'];
$ritmo_types = array_map(static fn (mixed $t): string => ChoiceLabels::of('price_bands.items.*.night_types.*', (string) $t), (array) ($ritmo_band['night_types'] ?? []));
?>
<?php echo esc_html(sprintf(
    /* translators: 1: lowest price, 2: highest price, 3: price plan, 4: kinds of night, 5: days ahead */
    __('%1$s – %2$s (%3$s), %4$s, up to %5$d days ahead', 'ritmo-revenue'),
    Money::format((float) ($ritmo_band['min_amount'] ?? 0), $view['currency']),
    Money::format((float) ($ritmo_band['max_amount'] ?? 0), $view['currency']),
    ChoiceLabels::of('price_bands.items.*.price_plan', (string) ($ritmo_band['price_plan'] ?? 'public')),
    $ritmo_types === [] ? __('every night', 'ritmo-revenue') : implode(', ', $ritmo_types),
    (int) ($ritmo_band['max_days_out'] ?? 0)
)); ?><?php if (!($ritmo_band['enabled'] ?? true)) : ?> <span class="ritmo-badge ritmo-tone-neutral"><?php esc_html_e('Off', 'ritmo-revenue'); ?></span><?php endif; ?>
