<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

/**
 * An English text made in the domain (the label and help of a rules setting), translated for the
 * reader. The domain cannot call WordPress; bin/make-pot.php lists these texts from the schema, so
 * they are in the translation files. The only call of __() with a text that is not literal.
 */
final class DomainText
{
    private function __construct()
    {
    }

    public static function t(string $english): string
    {
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the texts are listed by bin/make-pot.php from the schema.
        return $english === '' ? '' : __($english, 'ritmo-revenue');
    }
}
