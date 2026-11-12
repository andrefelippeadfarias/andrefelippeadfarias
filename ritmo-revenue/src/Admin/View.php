<?php

declare(strict_types=1);

namespace Ritmo\Admin;

use InvalidArgumentException;

/**
 * Renders a server-side template of templates/. A template reads its data from $view and
 * escapes every value it prints.
 */
final class View
{
    private function __construct()
    {
    }

    /**
     * @param string               $template path under templates/ without ".php" ("admin/dashboard")
     * @param array<string, mixed> $view
     */
    public static function render(string $template, array $view): void
    {
        if (preg_match('#^[a-z]+(/[a-z_-]+)+$#', $template) !== 1) {
            throw new InvalidArgumentException('A template is named by a plain path under templates/.');
        }
        $file = dirname(__DIR__, 2) . '/templates/' . $template . '.php';

        (static function (string $template, array $view): void {
            require $template;
        })($file, $view);
    }
}
