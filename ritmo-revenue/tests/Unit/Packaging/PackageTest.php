<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Packaging;

use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tools\Package\DistIgnore;
use Ritmo\Tools\Package\Php81Lint;
use Ritmo\Tools\Package\ReleaseScan;
use Ritmo\Tools\Package\ZipPackage;

require_once dirname(__DIR__, 3) . '/bin/package/DistIgnore.php';
require_once dirname(__DIR__, 3) . '/bin/package/Php81Lint.php';
require_once dirname(__DIR__, 3) . '/bin/package/ReleaseScan.php';
require_once dirname(__DIR__, 3) . '/bin/package/ZipPackage.php';

/**
 * The package of this repository, built the way bin/build.sh builds it (steps 3 to 6, without the
 * translations and the version, which are the integration's), and the scripts and documents of
 * the release.
 */
final class PackageTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            TempTree::remove($this->dir);
        }
    }

    public function testTheRepositoryBuildsAPackageThatPassesEveryCheck(): void
    {
        $root = Fixtures::pluginRoot();
        $rules = DistIgnore::fromFile($root . '/.distignore');
        $this->dir = TempTree::make([]);
        $stage = $this->dir . '/ritmo-revenue';
        foreach ($rules->files($root) as $relative) {
            if (!is_dir(dirname($stage . '/' . $relative))) {
                mkdir(dirname($stage . '/' . $relative), 0755, true);
            }
            copy($root . '/' . $relative, $stage . '/' . $relative);
        }
        $files = $rules->files($stage);

        foreach (['tests/', 'tools/', 'bin/', '.wp-test/', 'dist/', 'node_modules/'] as $folder) {
            self::assertSame([], array_values(array_filter($files, static fn (string $f): bool => str_starts_with($f, $folder))), "$folder is in the package");
        }
        foreach (['docs/PLANO.md', 'docs/IMPLANTACAO.md', 'composer.json', '.distignore', '.gitignore', 'README.md', 'docs/ARCHITECTURE.md', 'docs/CONTRACTS.md', 'docs/AI.md', 'docs/LICENCE-UPDATE.md', 'languages/ritmo-revenue.pot', 'languages/ritmo-revenue-pt_BR.po', 'languages/glossary-pt_BR.json'] as $file) {
            self::assertNotContains($file, $files);
        }
        self::assertSame(['docs/GUIA-DO-USUARIO.md'], array_values(array_filter($files, static fn (string $f): bool => str_starts_with($f, 'docs/'))), 'only the user guide goes with the plugin');
        foreach ([...ZipPackage::REQUIRED, 'CHANGELOG.md', 'docs/GUIA-DO-USUARIO.md', 'assets/screens.js', 'templates/admin/screens/layout.php'] as $file) {
            self::assertContains($file, $files);
        }
        self::assertSame([], Php81Lint::checkPaths([$stage]));
        self::assertSame([], ReleaseScan::fromOwnerTermsFile(Fixtures::path('owner-terms.json'))->scan($stage, $files));

        $zip = $this->dir . '/ritmo-revenue-test.zip';
        self::assertSame(count($files), ZipPackage::write($stage, $files, $zip, 'ritmo-revenue', 1789000000));
        self::assertSame([], ZipPackage::problems($zip, 'ritmo-revenue', $rules, $files));
        self::assertLessThanOrEqual(ZipPackage::MAX_BYTES, filesize($zip), 'the zip fits the default 2 MB upload limit');
        self::assertLessThan(2 * 1024 * 1024, ZipPackage::MAX_BYTES);
    }

    public function testTheBuildScriptRunsEveryCheckBeforeTheZip(): void
    {
        $root = Fixtures::pluginRoot();
        $script = (string) file_get_contents($root . '/bin/build.sh');

        self::assertTrue(is_executable($root . '/bin/build.sh'));
        self::assertTrue(is_executable($root . '/bin/e2e-zip.sh'));
        self::assertStringContainsString('set -euo pipefail', $script);
        // The commands, after the header comment.
        $script = substr($script, (int) strpos($script, 'set -euo pipefail'));
        $steps = ['php bin/make-pot.php', 'php bin/compile-l10n.php', 'build.php check-source', 'build.php stage', 'php -l "$1"', 'php bin/lint-php81.php "$STAGE"', 'build.php scan', 'build.php zip', 'build.php verify', 'sha256sum'];
        $at = -1;
        foreach ($steps as $step) {
            $position = strpos($script, $step);
            self::assertNotFalse($position, "bin/build.sh does not run $step");
            self::assertGreaterThan($at, $position, "bin/build.sh runs $step out of order");
            $at = $position;
        }
        self::assertStringNotContainsString('curl', $script, 'the build never calls the network');
    }

    public function testTheZipTestInstallsUsesAndRemovesThePluginLikeACustomer(): void
    {
        $root = Fixtures::pluginRoot();
        $script = (string) file_get_contents($root . '/bin/e2e-zip.sh');
        $script = substr($script, (int) strpos($script, 'set -euo pipefail'));
        $passes = ['wp-test-site.sh" start', 'zip-site.php" install', 'zip-site.php" snapshot', 'zip-install.js" install', 'zip-install.js" wizard', 'RITMO_E2E_LANG=en', 'locale.php" "$WP" pt_BR', 'RITMO_E2E_LANG=pt_BR', 'zip-site.php" present', 'zip-install.js" remove', 'zip-site.php" residue'];
        $at = -1;
        foreach ($passes as $pass) {
            $position = strpos($script, $pass);
            self::assertNotFalse($position, "bin/e2e-zip.sh does not run $pass");
            self::assertGreaterThan($at, $position, "bin/e2e-zip.sh runs $pass out of order");
            $at = $position;
        }
        $browser = (string) file_get_contents($root . '/tests/e2e/zip-install.js');
        foreach (['plugin-install.php?tab=upload', '#pluginzip', '#install-plugin-submit', 'action=activate', 'action=deactivate', ".delete a", 'delete_data', 'run-shadow', 'writes(requests).length === 0'] as $step) {
            self::assertStringContainsString($step, $browser);
        }
        self::assertStringContainsString('chromium.launch()', $browser);
    }

    public function testTheDeploymentGuideCoversTheDayAndTheSwitch(): void
    {
        $guide = (string) file_get_contents(Fixtures::pluginRoot() . '/docs/IMPLANTACAO.md');

        foreach (['## 1. Hospedagem', 'PHP', 'WordPress', 'HTTPS', 'wp-cron.php', 'bin/build.sh', 'RITMO_SECRET_KEY', 'Plugins › Adicionar novo › Enviar plugin', '### Beds24', '### PriceLabs', 'leitura', '## 6. O assistente', '## 7. Primeira conexão', 'Diagnóstico', '## 8. Protocolo de teste', 'Exportar CSV', 'Relatórios das rodadas', 'arquivo de suporte', '## 9. Critérios', '## 10. A troca', 'Desligue a skill primeiro', 'Nunca o modo autônomo no primeiro dia', 'RITMO_KILL_SWITCH', 'Plugins › Desativar'] as $part) {
            self::assertStringContainsString($part, $guide);
        }
        self::assertStringContainsString('nunca gravam ao mesmo tempo', $guide);
    }
}
