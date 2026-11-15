<?php

/**
 * Signs a release of the plugin for its update channel (docs/LICENCE-UPDATE.md). The private key
 * is read from the environment or from a file outside the repository, used for one run and never
 * written anywhere by this script; a key file inside the repository is refused.
 *
 *   php bin/sign-release.php keygen --out <file outside the repository>
 *       creates a key pair: the secret key (base64) goes to the new file (mode 0600), the public
 *       key is printed (it goes into RITMO_UPDATE_PUBLIC_KEY or the plugin's embedded key)
 *
 *   RITMO_SIGNING_KEY=<base64> php bin/sign-release.php sign --zip <package.zip> --version 1.2.0 \
 *       --package-url https://updates.example.com/ritmo-revenue-1.2.0.zip [--out <metadata.json>] \
 *       [--key-file <file outside the repository>] [--name "Ritmo Revenue"] [--requires 6.5] \
 *       [--requires-php 8.1] [--tested 6.8] [--released 2026-11-15] [--changelog <text file, one line per change>]
 *       writes the signed metadata (to stdout without --out)
 *
 *   php bin/sign-release.php verify --metadata <metadata.json> --zip <package.zip> --public-key <base64> \
 *       --metadata-url <URL the metadata is served at> [--installed 0.0.0]
 *       checks both as the plugin will (exit 0 when they pass)
 *
 *   php bin/sign-release.php public-key [--key-file <file>]   prints the public key of the signing key
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use Ritmo\Application\Update\ManifestVerifier;
use Ritmo\Application\Update\PackageVerifier;
use Ritmo\Application\Update\ReleaseSigner;
use Ritmo\Application\Update\TrustedKeys;
use Ritmo\Application\Update\UpdateRefused;
use Ritmo\Application\Update\UpdateSettings;
use Ritmo\Connectors\Licence\ServerUrl;

/**
 * @param list<string> $args
 *
 * @return array<string, string>
 */
function ritmo_sign_options(array $args): array
{
    $options = [];
    for ($i = 0; $i < count($args); ++$i) {
        if (!str_starts_with($args[$i], '--') || !isset($args[$i + 1])) {
            throw new InvalidArgumentException('Options are "--name value" pairs: ' . $args[$i]);
        }
        $options[substr($args[$i], 2)] = $args[++$i];
    }

    return $options;
}

/**
 * The folder of the git repository the plugin lives in (or the plugin folder without git).
 */
function ritmo_sign_repository(): string
{
    $dir = dirname(__DIR__);
    for ($at = $dir; $at !== dirname($at); $at = dirname($at)) {
        if (file_exists($at . '/.git')) {
            return $at;
        }
    }

    return $dir;
}

function ritmo_sign_outside_repository(string $path, string $what): string
{
    $folder = realpath(dirname($path));
    if ($folder === false) {
        throw new InvalidArgumentException($what . ': the folder does not exist.');
    }
    $repository = realpath(ritmo_sign_repository());
    $full = $folder . '/' . basename($path);
    if ($repository !== false && ($folder === $repository || str_starts_with($folder . '/', $repository . '/'))) {
        throw new InvalidArgumentException($what . ' must be outside the repository (' . $repository . ').');
    }

    return $full;
}

/**
 * @param array<string, string> $options
 */
function ritmo_sign_secret(array $options): string
{
    if (isset($options['key-file'])) {
        $file = ritmo_sign_outside_repository($options['key-file'], 'The key file');
        $text = @file_get_contents($file);
        if ($text === false) {
            throw new InvalidArgumentException('The key file cannot be read.');
        }

        return ReleaseSigner::secretKey($text);
    }
    $env = getenv('RITMO_SIGNING_KEY');
    if (!is_string($env) || trim($env) === '') {
        throw new InvalidArgumentException('Give the signing key in RITMO_SIGNING_KEY or with --key-file.');
    }

    return ReleaseSigner::secretKey($env);
}

/**
 * @param array<string, string> $options
 */
function ritmo_sign_required(array $options, string $name): string
{
    if (!isset($options[$name]) || $options[$name] === '') {
        throw new InvalidArgumentException('Missing --' . $name . '.');
    }

    return $options[$name];
}

/**
 * @param list<string> $argv
 */
function ritmo_sign_main(array $argv): int
{
    $command = $argv[1] ?? '';
    $options = ritmo_sign_options(array_slice($argv, 2));
    switch ($command) {
        case 'keygen':
            $out = ritmo_sign_outside_repository(ritmo_sign_required($options, 'out'), 'The secret key file');
            if (file_exists($out)) {
                throw new InvalidArgumentException('The secret key file already exists; nothing was overwritten.');
            }
            $pair = ReleaseSigner::keypair();
            $old = umask(0077);
            $written = file_put_contents($out, $pair['secret'] . "\n", LOCK_EX);
            umask($old);
            if ($written === false) {
                throw new RuntimeException('The secret key file could not be written.');
            }
            chmod($out, 0600);
            fwrite(STDOUT, $pair['public'] . "\n");
            fwrite(STDERR, "Secret key written to $out (keep it out of the repository and of the site).\n");

            return 0;
        case 'public-key':
            fwrite(STDOUT, ReleaseSigner::publicKey(ritmo_sign_secret($options)) . "\n");

            return 0;
        case 'sign':
            $zip = (string) @file_get_contents(ritmo_sign_required($options, 'zip'));
            if ($zip === '') {
                throw new InvalidArgumentException('The package cannot be read.');
            }
            $release = ['version' => ritmo_sign_required($options, 'version'), 'package' => ritmo_sign_required($options, 'package-url')];
            foreach (['name' => 'name', 'requires' => 'requires', 'requires-php' => 'requires_php', 'tested' => 'tested', 'released' => 'released'] as $option => $field) {
                if (isset($options[$option])) {
                    $release[$field] = $options[$option];
                }
            }
            if (isset($options['changelog'])) {
                $lines = @file($options['changelog'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines === false) {
                    throw new InvalidArgumentException('The changelog file cannot be read.');
                }
                $release['changelog'] = array_values(array_map('trim', $lines));
            }
            $envelope = ReleaseSigner::sign(ritmo_sign_secret($options), $zip, $release);
            if (isset($options['out'])) {
                if (file_put_contents($options['out'], $envelope) === false) {
                    throw new RuntimeException('The metadata file could not be written.');
                }
                fwrite(STDERR, 'Signed metadata written to ' . $options['out'] . "\n");
            } else {
                fwrite(STDOUT, $envelope);
            }

            return 0;
        case 'verify':
            $settings = new UpdateSettings(
                ServerUrl::parse(ritmo_sign_required($options, 'metadata-url'), true),
                TrustedKeys::parse(ritmo_sign_required($options, 'public-key')),
                $options['installed'] ?? '0.0.0',
                'ritmo-revenue',
                'ritmo-revenue/ritmo-revenue.php',
                true,
            );
            $manifest = (new ManifestVerifier($settings))->verify((string) @file_get_contents(ritmo_sign_required($options, 'metadata')));
            PackageVerifier::verify((string) @file_get_contents(ritmo_sign_required($options, 'zip')), $manifest, $settings->keys);
            fwrite(STDOUT, 'OK: version ' . $manifest->version . ', ' . $manifest->size . " bytes, signatures valid.\n");

            return 0;
        default:
            fwrite(STDERR, "Usage: php bin/sign-release.php keygen|sign|verify|public-key [options] (see the comment at the top)\n");

            return 2;
    }
}

try {
    exit(ritmo_sign_main($argv));
} catch (UpdateRefused $refused) {
    fwrite(STDERR, 'Refused (' . $refused->reason . '): ' . $refused->getMessage() . "\n");
    exit(1);
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}
