<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

use InvalidArgumentException;
use OutOfBoundsException;
use Ritmo\Admin\RefusalText;
use Ritmo\Admin\Editors\EditorText;
use Ritmo\Application\Approval\ApprovalRefused;
use Ritmo\Application\Dashboard\Setup\RoomRowsRefused;
use Ritmo\Application\Mode\ModeRefused;
use Ritmo\Application\Rules\RulesRefused;
use Ritmo\Domain\Config\InvalidConfig;
use Throwable;

/**
 * What a screen says when an action or a card fails: the plugin's own refusals in their words;
 * anything else as "Something went wrong. Nothing was changed. Reference E-1a2b3c4d", with the
 * reference written to the log next to the exception class and the place (never the message of
 * a foreign exception, which may carry data).
 */
final class ErrorText
{
    private function __construct()
    {
    }

    /**
     * @param (callable(string, array<string, mixed>): void)|null $log receives the reference and the context
     */
    public static function from(Throwable $failure, ?callable $log = null): string
    {
        if ($failure instanceof ModeRefused) {
            return RefusalText::mode($failure);
        }
        if ($failure instanceof ApprovalRefused) {
            return RefusalText::approval($failure);
        }
        if ($failure instanceof RulesRefused) {
            return EditorText::refusal($failure);
        }
        if ($failure instanceof RoomRowsRefused) {
            return __('Some rooms could not be saved: nothing was saved.', 'ritmo-revenue');
        }
        if ($failure instanceof InvalidConfig) {
            return self::config($failure);
        }
        if ($failure instanceof UserFacing) {
            return $failure->getMessage();
        }
        if (self::ownValidation($failure)) {
            return OwnMessageText::of($failure->getMessage()) ?? $failure->getMessage();
        }
        $reference = self::reference($failure);
        if ($log !== null) {
            $log($reference, ['class' => $failure::class, 'at' => basename($failure->getFile()) . ':' . $failure->getLine()]);
        }

        /* translators: %s: a reference such as E-1a2b3c4d */
        return sprintf(__('Something went wrong. Nothing was changed. Reference %s', 'ritmo-revenue'), $reference);
    }

    /**
     * A rule set, preset or settings that were not accepted, problem by problem in the reader's
     * language (the path of each value as it is in the file).
     */
    public static function config(InvalidConfig $failure): string
    {
        $errors = $failure->errors();
        $shown = array_map(
            static fn (\Ritmo\Domain\Config\ValidationError $error): string => ($error->path === '' ? __('the file', 'ritmo-revenue') : $error->path) . ': ' . EditorText::problem($error),
            array_slice($errors, 0, 5),
        );
        $more = count($errors) - count($shown);
        /* translators: %s: the problems found, each as "<path of the value>: <what is wrong>", separated by ";" */
        $text = sprintf(__('These values are not accepted, so nothing was saved: %s', 'ritmo-revenue'), implode('; ', $shown));
        if ($more > 0) {
            /* translators: %d: how many more problems were found */
            $text .= ' ' . sprintf(_n('(and %d more problem)', '(and %d more problems)', $more, 'ritmo-revenue'), $more);
        }

        return $text;
    }

    /**
     * A validation refusal thrown by the plugin's own code (its message is a sentence written for
     * a person, as the M1-M3 forms show it); the same exception from anywhere else is not shown.
     */
    private static function ownValidation(Throwable $failure): bool
    {
        return ($failure instanceof InvalidArgumentException || $failure instanceof OutOfBoundsException)
            && str_starts_with($failure->getFile(), dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
    }

    public static function reference(Throwable $failure): string
    {
        return 'E-' . substr(hash('sha256', $failure::class . '|' . $failure->getFile() . '|' . $failure->getLine() . '|' . spl_object_id($failure) . '|' . microtime()), 0, 8);
    }
}
