<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use InvalidArgumentException;
use Ritmo\Domain\Config\Preset;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\Csv\CsvBytes;

/**
 * The preset file of the versions tab: the rules in force with the properties and room types, in
 * the plugin's preset format, downloaded as JSON (no key, no booking); and the upload of one,
 * checked before it is read (one JSON file of at most 1 MB that is not a script).
 */
final class PresetFile
{
    public const MAX_BYTES = 1048576;

    private function __construct()
    {
    }

    public static function send(): void
    {
        $services = EditorServices::instance()->services();
        $preset = new Preset(
            sprintf('Rules version %d', $services->ruleSets()->currentVersion() ?? 0),
            'Exported from the Rules screen',
            $services->properties()->all(),
            $services->roomTypes()->all(),
            $services->ruleSets()->current() ?? RuleSet::defaults(),
        );
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="ritmo-revenue-rules-' . gmdate('Y-m-d') . '.json"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo wp_json_encode($preset->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON download, not HTML
    }

    /**
     * The text of the uploaded preset file.
     *
     * @throws InvalidArgumentException with a sentence for the person
     */
    public static function upload(string $field): string
    {
        $file = $_FILES[$field] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by the action dispatcher
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException(__('Choose a preset file to upload.', 'ritmo-revenue'));
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES || (int) filesize($file['tmp_name']) > self::MAX_BYTES) {
            throw new InvalidArgumentException(__('A preset file has at most 1 MB.', 'ritmo-revenue'));
        }
        if (strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'json') {
            throw new InvalidArgumentException(__('A preset file ends in .json.', 'ritmo-revenue'));
        }
        $text = (string) file_get_contents($file['tmp_name']);
        if (CsvBytes::looksBinaryOrCode(substr($text, 0, CsvBytes::HEAD_BYTES)) || !CsvBytes::isUtf8($text)) {
            throw new InvalidArgumentException(__('This is not a preset file of this plugin.', 'ritmo-revenue'));
        }

        return CsvBytes::withoutBom($text);
    }
}
