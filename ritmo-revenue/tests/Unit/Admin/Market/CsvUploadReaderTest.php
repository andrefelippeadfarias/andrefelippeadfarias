<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Admin\Market;

use PHPUnit\Framework\TestCase;
use Ritmo\Admin\Market\CsvUploadReader;
use Ritmo\Application\Market\MarketRefused;
use Ritmo\Application\Market\UploadedFile;
use Ritmo\Domain\Market\Csv\MarketCsvProblem;

/**
 * The one reader of an uploaded price file (spec 05, 6.4; market spec 1.2): every check happens
 * before the file is read, the file is read in place with fgetcsv and never moved, and a file
 * that is not CSV text is refused whatever its name says.
 */
final class CsvUploadReaderTest extends TestCase
{
    private const MAX = 2097152;

    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/ritmo-csv-' . bin2hex(random_bytes(6));
        mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->folder . '/*') as $file) {
            unlink((string) $file);
        }
        rmdir($this->folder);
    }

    public function testAValidFileIsReadInPlaceAndNeverMoved(): void
    {
        $text = "competitor,check_in,check_out,price\ncomp-01,2027-01-15,2027-01-17,1840.00\n";
        $upload = $this->upload('../../Prices of the week.csv', $text);

        $file = self::reader()->read($upload, self::MAX, 100);

        self::assertSame('Prices-of-the-week.csv', $file->name, 'only the base name, with safe characters');
        self::assertSame(hash('sha256', $text), $file->sha256);
        self::assertSame(strlen($text), $file->bytes);
        self::assertSame([['competitor', 'check_in', 'check_out', 'price'], ['comp-01', '2027-01-15', '2027-01-17', '1840.00']], $file->records);
        self::assertFileExists($upload['tmp_name'], 'read in place: the upload is not moved');
        self::assertSame([$upload['tmp_name']], glob($this->folder . '/*'), 'and nothing is copied next to it');
    }

    public function testABomIsDroppedAndASemicolonFileIsSplitBySemicolons(): void
    {
        $file = self::reader()->read($this->upload('p.csv', "\xEF\xBB\xBFcompetitor;check_in;check_out;price\ncomp-01;2027-01-15;2027-01-17;1840,50\n"), self::MAX, 100);

        self::assertSame(['competitor', 'check_in', 'check_out', 'price'], $file->records[0]);
        self::assertSame('1840,50', $file->records[1][3]);
    }

    public function testAWindows1252FileIsConvertedToUtf8(): void
    {
        $file = self::reader()->read($this->upload('p.csv', "competitor,check_in,check_out,price\nPousada S\xE3o Jo\xE3o,2027-01-15,2027-01-17,900\n"), self::MAX, 100);

        self::assertSame('Pousada São João', $file->records[1][0]);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function notText(): iterable
    {
        yield 'NUL byte' => ['p.csv', "competitor,check_in\x00,check_out,price\n"];
        yield 'PHP code' => ['p.csv', "<?php echo 'x';\ncompetitor,check_in,check_out,price\n"];
        yield 'HTML page' => ['p.csv', "<html><body>competitor</body></html>\n"];
        yield 'zip archive' => ['p.csv', "PK\x03\x04competitor"];
        yield 'PDF document' => ['p.csv', "%PDF-1.4\n"];
        yield 'php name' => ['prices.php', "competitor,check_in,check_out,price\n"];
        yield 'html name' => ['prices.html', "competitor,check_in,check_out,price\n"];
        yield 'csv inside the name only' => ['prices.csv.php', "competitor,check_in,check_out,price\n"];
    }

    /**
     * @dataProvider notText
     */
    public function testWhatIsNotCsvTextIsRefused(string $name, string $text): void
    {
        $this->assertRefused(MarketCsvProblem::NOT_CSV, fn (): UploadedFile => self::reader()->read($this->upload($name, $text), self::MAX, 100));
    }

    public function testAFileTheSiteDoesNotTakeAsCsvIsRefused(): void
    {
        $reader = new CsvUploadReader(static fn (string $tmp): bool => true, static fn (string $tmp, string $name): bool => false);

        $this->assertRefused(MarketCsvProblem::NOT_CSV, fn (): UploadedFile => $reader->read($this->upload('p.csv', "competitor\n"), self::MAX, 100));
    }

    public function testATooLargeFileIsRefusedBeforeItIsLookedAt(): void
    {
        $looked = false;
        $reader = new CsvUploadReader(static fn (string $tmp): bool => true, static function () use (&$looked): bool {
            $looked = true;

            return true;
        });

        $this->assertRefused(MarketCsvProblem::FILE_TOO_LARGE, fn (): UploadedFile => $reader->read($this->upload('p.csv', str_repeat('a', 2000)), 1024, 100));
        self::assertFalse($looked, 'the size is checked first');
        $this->assertRefused(MarketCsvProblem::FILE_TOO_LARGE, fn (): UploadedFile => $reader->read(['error' => UPLOAD_ERR_INI_SIZE], 1024, 100));
        $this->assertRefused(MarketCsvProblem::FILE_TOO_LARGE, fn (): UploadedFile => $reader->read(['size' => 5000] + $this->upload('p.csv', 'a'), 1024, 100), 'the declared size counts too');
    }

    public function testAMissingEmptyOrForgedUploadIsRefused(): void
    {
        $this->assertRefused(MarketCsvProblem::EMPTY_FILE, fn (): UploadedFile => self::reader()->read([], self::MAX, 100));
        $this->assertRefused(MarketCsvProblem::EMPTY_FILE, fn (): UploadedFile => self::reader()->read(['error' => UPLOAD_ERR_NO_FILE], self::MAX, 100));
        $this->assertRefused(MarketCsvProblem::EMPTY_FILE, fn (): UploadedFile => self::reader()->read($this->upload('p.csv', ''), self::MAX, 100));
        $forged = new CsvUploadReader(static fn (string $tmp): bool => false, static fn (): bool => true);
        $this->assertRefused(MarketCsvProblem::EMPTY_FILE, fn (): UploadedFile => $forged->read($this->upload('p.csv', "competitor\n"), self::MAX, 100), 'a path that is not an upload of this request');
    }

    public function testReadingStopsSoonAfterTheRowLimit(): void
    {
        $text = "competitor,check_in,check_out,price\n" . str_repeat("comp-01,2027-01-15,2027-01-17,100\n", 400);

        $file = self::reader()->read($this->upload('p.csv', $text), self::MAX, 50);

        self::assertLessThan(400, count($file->records), 'a long file is not read to the end');
        self::assertGreaterThan(51, count($file->records), 'but far enough for the reader to say it has too many rows');
    }

    private static function reader(): CsvUploadReader
    {
        return new CsvUploadReader(static fn (string $tmp): bool => is_file($tmp), static fn (string $tmp, string $name): bool => true);
    }

    /**
     * An entry of $_FILES for a file written in the test folder.
     *
     * @return array{name: string, tmp_name: string, size: int, error: int}
     */
    private function upload(string $name, string $text): array
    {
        $tmp = $this->folder . '/php' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $text);

        return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($text), 'error' => UPLOAD_ERR_OK];
    }

    private function assertRefused(string $code, callable $read, string $message = ''): void
    {
        try {
            $read();
            self::fail('refused: ' . $code . ' ' . $message);
        } catch (MarketRefused $refused) {
            self::assertSame([MarketRefused::FILE_ERROR, $code], [$refused->reason, $refused->params['code'] ?? null], $message);
        }
    }
}
