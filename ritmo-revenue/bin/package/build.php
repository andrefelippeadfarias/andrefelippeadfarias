<?php

/**
 * The steps of bin/build.sh that need PHP (run them through bin/build.sh):
 *
 *   php bin/package/build.php version                 the version of the plugin header
 *   php bin/package/build.php check-source            versions, readme.txt, changelogs, every text translated
 *   php bin/package/build.php stage <folder>          copies the package's files (.distignore, no hidden file) into <folder>
 *   php bin/package/build.php scan <folder>           owner's terms, secrets and file types of a staged package
 *   php bin/package/build.php zip <folder> <zip>      writes the zip (timestamp: SOURCE_DATE_EPOCH, else now)
 *   php bin/package/build.php verify <zip> <folder>   the zip holds exactly the staged package, in one folder
 *
 * Exits 1 when a step finds a problem, 2 on a wrong call.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/i18n/Po.php';
require __DIR__ . '/DistIgnore.php';
require __DIR__ . '/ReleaseInfo.php';
require __DIR__ . '/ReleaseScan.php';
require __DIR__ . '/ZipPackage.php';

use Ritmo\Tools\Package\DistIgnore;
use Ritmo\Tools\Package\ReleaseInfo;
use Ritmo\Tools\Package\ReleaseScan;
use Ritmo\Tools\Package\ZipPackage;

$root = dirname(__DIR__, 2);

/**
 * @param list<string> $problems
 */
function ritmo_build_report(string $step, array $problems): int
{
    if ($problems === []) {
        echo "ok   $step\n";

        return 0;
    }
    echo "FAIL $step\n";
    foreach ($problems as $problem) {
        echo "     $problem\n";
    }

    return 1;
}

function ritmo_build_usage(): int
{
    fwrite(STDERR, "Usage: php bin/package/build.php version|check-source|stage <folder>|scan <folder>|zip <folder> <zip>|verify <zip> <folder>\n");

    return 2;
}

try {
    $rules = DistIgnore::fromFile($root . '/.distignore');
    switch ($argv[1] ?? '') {
        case 'version':
            echo ReleaseInfo::version($root) . "\n";
            exit(ReleaseInfo::version($root) === '' ? 1 : 0);

        case 'check-source':
            $status = ritmo_build_report('version ' . ReleaseInfo::version($root) . ' in the header, the constant, readme.txt and the changelogs', ReleaseInfo::problems($root));
            $pot = (string) @file_get_contents($root . '/languages/ritmo-revenue.pot');
            $pos = glob($root . '/languages/ritmo-revenue-*.po') ?: [];
            if ($pot === '' || $pos === []) {
                $status |= ritmo_build_report('translations', ['languages/ has no .pot or no .po.']);
            }
            foreach ($pos as $po) {
                $status |= ritmo_build_report('every text translated in ' . basename($po), ReleaseInfo::untranslated($pot, (string) file_get_contents($po)));
            }
            exit($status);

        case 'stage':
            $target = $argv[2] ?? '';
            if ($target === '' || (is_dir($target) && (scandir($target) ?: []) !== ['.', '..'])) {
                fwrite(STDERR, "stage needs a new or empty folder.\n");
                exit(2);
            }
            $files = $rules->files($root);
            foreach ($files as $relative) {
                $to = $target . '/' . $relative;
                if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0755, true) && !is_dir(dirname($to))) {
                    throw new RuntimeException('Cannot create ' . dirname($to));
                }
                if (!copy($root . '/' . $relative, $to)) {
                    throw new RuntimeException("Cannot copy $relative");
                }
            }
            echo 'ok   staged ' . count($files) . " files\n";
            exit(0);

        case 'scan':
            $dir = rtrim($argv[2] ?? '', '/');
            if ($dir === '' || !is_dir($dir)) {
                exit(ritmo_build_usage());
            }
            $scan = ReleaseScan::fromOwnerTermsFile($root . '/tests/fixtures/owner-terms.json');
            exit(ritmo_build_report('nothing of the owner, no secret, only plugin file types', $scan->scan($dir, $rules->files($dir))));

        case 'zip':
            [$dir, $zip] = [rtrim($argv[2] ?? '', '/'), $argv[3] ?? ''];
            if ($dir === '' || $zip === '' || !is_dir($dir)) {
                exit(ritmo_build_usage());
            }
            $epoch = getenv('SOURCE_DATE_EPOCH');
            $mtime = is_string($epoch) && ctype_digit($epoch) ? (int) $epoch : time();
            $count = ZipPackage::write($dir, $rules->files($dir), $zip, ReleaseInfo::SLUG, $mtime);
            echo "ok   zip written ($count files, " . ReleaseInfo::SLUG . "/, timestamp " . gmdate('Y-m-d H:i:s', $mtime) . " UTC)\n";
            exit(0);

        case 'verify':
            [$zip, $dir] = [$argv[2] ?? '', rtrim($argv[3] ?? '', '/')];
            if ($zip === '' || $dir === '' || !is_file($zip) || !is_dir($dir)) {
                exit(ritmo_build_usage());
            }
            exit(ritmo_build_report('the zip holds the staged package in ' . ReleaseInfo::SLUG . '/', ZipPackage::problems($zip, ReleaseInfo::SLUG, $rules, $rules->files($dir))));

        default:
            exit(ritmo_build_usage());
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, 'FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
