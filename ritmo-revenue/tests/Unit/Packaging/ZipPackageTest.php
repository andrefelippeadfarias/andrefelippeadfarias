<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Packaging;

use PHPUnit\Framework\TestCase;
use Ritmo\Tools\Package\DistIgnore;
use Ritmo\Tools\Package\ZipPackage;
use ZipArchive;

require_once dirname(__DIR__, 3) . '/bin/package/DistIgnore.php';
require_once dirname(__DIR__, 3) . '/bin/package/ZipPackage.php';

/**
 * The zip: one folder, sorted, fixed permissions and time, the same bytes for the same files; the
 * check of a built zip names what is wrong.
 */
final class ZipPackageTest extends TestCase
{
    private const MTIME = 1789000000;

    private string $dir = '';

    protected function setUp(): void
    {
        $files = array_fill_keys(ZipPackage::REQUIRED, '<?php // x');
        $files['src/Domain/B.php'] = '<?php // b';
        $files['assets/a.js'] = 'void 0;';
        $this->dir = TempTree::make($files);
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->dir);
    }

    public function testTheZipHoldsOneFolderSortedWithFixedPermissionsAndTime(): void
    {
        $files = (new DistIgnore([]))->files($this->dir);
        $zip = $this->dir . '/out/package.zip';
        mkdir(dirname($zip));

        self::assertSame(count($files), ZipPackage::write($this->dir, $files, $zip, 'ritmo-revenue', self::MTIME));

        $entries = ZipPackage::entries($zip);
        self::assertSame('ritmo-revenue/', $entries[0]);
        foreach ($entries as $name) {
            self::assertStringStartsWith('ritmo-revenue/', $name);
        }
        $names = array_values(array_filter($entries, static fn (string $n): bool => !str_ends_with($n, '/')));
        self::assertSame(array_map(static fn (string $f): string => 'ritmo-revenue/' . $f, $files), $names);

        $archive = new ZipArchive();
        self::assertTrue($archive->open($zip));
        for ($i = 0; $i < $archive->numFiles; ++$i) {
            $stat = $archive->statIndex($i);
            $archive->getExternalAttributesIndex($i, $system, $attributes);
            self::assertSame(ZipArchive::OPSYS_UNIX, $system);
            self::assertSame(str_ends_with($stat['name'], '/') ? 040755 : 0100644, $attributes >> 16, $stat['name']);
            self::assertLessThanOrEqual(2, abs($stat['mtime'] - self::MTIME), $stat['name']);
        }
        self::assertSame('<?php // b', $archive->getFromName('ritmo-revenue/src/Domain/B.php'));
        $archive->close();
        self::assertSame([], ZipPackage::problems($zip, 'ritmo-revenue', new DistIgnore([]), $files));
    }

    public function testTheSameFilesGiveTheSameBytes(): void
    {
        $files = (new DistIgnore([]))->files($this->dir);
        $first = sys_get_temp_dir() . '/ritmo-packaging-zip-a-' . getmypid() . '.zip';
        $second = sys_get_temp_dir() . '/ritmo-packaging-zip-b-' . getmypid() . '.zip';
        try {
            ZipPackage::write($this->dir, $files, $first, 'ritmo-revenue', self::MTIME);
            touch($this->dir . '/src/Domain/B.php', self::MTIME + 5000);
            ZipPackage::write($this->dir, array_reverse($files), $second, 'ritmo-revenue', self::MTIME);

            self::assertSame(hash_file('sha256', $first), hash_file('sha256', $second));
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }

    public function testABadZipIsDescribed(): void
    {
        $zip = $this->dir . '-bad.zip';
        $archive = new ZipArchive();
        $archive->open($zip, ZipArchive::CREATE);
        $archive->addFromString('ritmo-revenue/ritmo-revenue.php', '<?php');
        $archive->addFromString('ritmo-revenue/tests/Leak.php', '<?php');
        $archive->addFromString('other/readme.txt', 'x');
        $archive->close();
        try {
            $problems = ZipPackage::problems($zip, 'ritmo-revenue', new DistIgnore(['/tests/']), ['ritmo-revenue.php']);
        } finally {
            unlink($zip);
        }

        self::assertContains('tests/Leak.php is left out by .distignore but is in the zip.', $problems);
        self::assertContains('other/readme.txt is outside the folder ritmo-revenue/.', $problems);
        self::assertContains('readme.txt is missing from the zip.', $problems);
        self::assertContains('languages/ritmo-revenue-pt_BR.l10n.php is missing from the zip.', $problems);
        self::assertContains('The zip holds 2 files, the staged package 1.', $problems);
    }

    public function testAZipOverTheDefaultUploadLimitIsRefused(): void
    {
        $zip = $this->dir . '-big.zip';
        $archive = new ZipArchive();
        $archive->open($zip, ZipArchive::CREATE);
        $archive->addFromString('ritmo-revenue/ritmo-revenue.php', random_bytes(ZipPackage::MAX_BYTES + 1000));
        $archive->setCompressionName('ritmo-revenue/ritmo-revenue.php', ZipArchive::CM_STORE);
        $archive->close();
        try {
            $problems = ZipPackage::problems($zip, 'ritmo-revenue', new DistIgnore(['/tests/']), ['ritmo-revenue.php']);
        } finally {
            unlink($zip);
        }

        self::assertNotEmpty(array_filter($problems, static fn (string $p): bool => str_contains($p, 'default upload limit (2 MB)')));
    }

    public function testAFileThatIsNotAZipIsSaidSo(): void
    {
        file_put_contents($this->dir . '/fake.zip', 'not a zip');

        self::assertSame([$this->dir . '/fake.zip is not a readable zip.'], ZipPackage::problems($this->dir . '/fake.zip', 'ritmo-revenue', new DistIgnore([]), []));
    }
}
