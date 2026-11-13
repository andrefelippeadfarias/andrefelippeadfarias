<?php
/**
 * A floor, ceiling, listing value or price change: money first, the percent in words beside it.
 *
 * @var array<string, mixed> $view ['price' => {text, note, sr}]
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$ritmo_price = $view['price'];
?>
<span class="ritmo-price" data-ritmo="price"><?php echo esc_html($ritmo_price['text']); ?><?php if ($ritmo_price['note'] !== '') : ?> <span class="ritmo-pct">(<?php echo esc_html($ritmo_price['note']); ?>)</span><?php endif; ?></span>
