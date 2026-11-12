<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Setup\SampleCsv;
use Ritmo\Application\Setup\SetupService;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\PriceSampleRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Unit\Infrastructure\Support\MigratedDatabase;

final class SetupServiceTest extends TestCase
{
    private PdoDatabase $db;

    private MovableClock $clock;

    private SetupService $setup;

    protected function setUp(): void
    {
        $this->db = MigratedDatabase::create();
        $this->clock = new MovableClock('2027-03-01T13:00:00Z');
        $this->setup = new SetupService(
            $this->db,
            new PropertyRepository($this->db, $this->clock),
            new RoomTypeRepository($this->db, $this->clock),
            new RuleSetRepository($this->db, $this->clock),
            new PriceSampleRepository($this->db),
            new MarketAnchorRepository($this->db, $this->clock),
            $this->clock,
        );
    }

    public function testAPropertyIsCreatedInShadowMode(): void
    {
        $property = $this->setup->createProperty(['id' => 'inn', 'name' => ' Example Inn ', 'timezone' => 'America/Sao_Paulo', 'currency' => 'brl']);

        self::assertSame('Example Inn', $property->name);
        self::assertSame('BRL', $property->currency);
        self::assertSame('standard', $property->tier);
        self::assertSame(PropertyMode::Shadow, (new PropertyRepository($this->db, $this->clock))->find('inn')?->mode);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badProperties(): iterable
    {
        $good = ['id' => 'inn2', 'name' => 'Inn', 'timezone' => 'Europe/Lisbon', 'currency' => 'EUR'];
        yield 'id with spaces' => [['id' => 'my inn'] + $good, 'property id'];
        yield 'taken id' => [['id' => 'inn'] + $good, 'already exists'];
        yield 'no name' => [['name' => ''] + $good, 'name'];
        yield 'unknown timezone' => [['timezone' => 'Mars/Base'] + $good, 'timezone'];
        yield 'currency' => [['currency' => 'EURO'] + $good, 'currency'];
        yield 'tier' => [['tier' => 'Top Tier'] + $good, 'tier'];
        yield 'not text' => [['name' => ['x']] + $good, 'name'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('badProperties')]
    public function testWrongPropertyValuesAreRefusedWithAReason(array $input, string $mentions): void
    {
        $this->setup->createProperty(['id' => 'inn', 'name' => 'Inn', 'timezone' => 'UTC', 'currency' => 'EUR']);

        try {
            $this->setup->createProperty($input);
            self::fail('accepted');
        } catch (InvalidArgumentException $refused) {
            self::assertStringContainsString($mentions, $refused->getMessage());
        }
    }

    public function testRoomTypesAreMappedToThePmsAndThePricingSystem(): void
    {
        $this->setup->createProperty(['id' => 'inn', 'name' => 'Inn', 'timezone' => 'UTC', 'currency' => 'EUR']);

        $room = $this->setup->saveRoomType(['property_id' => 'inn', 'code' => 'STD', 'name' => 'Standard', 'units' => '4', 'pms_ref' => '902001', 'pricing_ref' => 'L-1', 'base' => '480', 'min' => '320.50', 'max' => '1600', 'reference_min' => '300', 'tags' => 'Tub, view ,']);

        self::assertSame(['tub', 'view'], $room->tags);
        self::assertSame(320.5, $room->min);
        self::assertSame(['902001' => 'STD'], (new RoomTypeRepository($this->db, $this->clock))->pmsMap());

        foreach ([['units' => 'four'], ['base' => '-1'], ['property_id' => 'nowhere'], ['code' => 'bad code']] as $wrong) {
            try {
                $this->setup->saveRoomType($wrong + ['property_id' => 'inn', 'code' => 'STD', 'name' => 'Standard', 'units' => '4', 'base' => '480', 'min' => '320', 'max' => '1600', 'reference_min' => '300']);
                self::fail('accepted ' . json_encode($wrong));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testThePresetOfThePluginImportsAsANewRuleVersion(): void
    {
        $summary = $this->setup->importPreset((string) file_get_contents(Fixtures::pluginRoot() . '/presets/default.json'));

        self::assertSame(['properties' => 0, 'room_types' => 0, 'rules_version' => 1], $summary);
    }

    public function testAnImportedPropertyAlwaysStartsInShadowMode(): void
    {
        $preset = ['format' => 'ritmo-revenue-preset', 'format_version' => 1, 'name' => 'Test', 'description' => '',
            'properties' => [['id' => 'inn', 'name' => 'Inn', 'timezone' => 'UTC', 'currency' => 'EUR', 'mode' => 'auto', 'tier' => 'standard', 'occupancy_goals' => [],
                'room_types' => [['code' => 'STD', 'name' => 'Standard', 'units' => 2, 'pms_ref' => '1', 'pricing_ref' => 'L', 'base' => 500, 'min' => 300, 'max' => 1500, 'reference_min' => 280, 'tags' => []]]]],
            'rules' => []];

        $summary = $this->setup->importPreset((string) json_encode($preset));

        self::assertSame(['properties' => 1, 'room_types' => 1, 'rules_version' => 1], $summary);
        self::assertSame(PropertyMode::Shadow, (new PropertyRepository($this->db, $this->clock))->find('inn')?->mode);
    }

    public function testAWrongPresetListsItsErrorsAndChangesNothing(): void
    {
        try {
            $this->setup->importPreset('{"format": "ritmo-revenue-preset", "format_version": 1, "name": "x", "description": "", "properties": [], "rules": {"pace": {"band": -5}}}');
            self::fail('accepted');
        } catch (InvalidConfig $refused) {
            self::assertNotSame([], $refused->errors());
        }
        self::assertNull((new RuleSetRepository($this->db, $this->clock))->currentVersion());

        $this->expectException(InvalidArgumentException::class);
        $this->setup->importPreset('not json');
    }

    public function testPastedPricesBecomeTheMarketAnchorOfTheirNights(): void
    {
        $this->setup->createProperty(['id' => 'inn', 'name' => 'Inn', 'timezone' => 'UTC', 'currency' => 'EUR']);
        $csv = "date,price\n2027-03-10,400\n2027-03-10,450\n2027-03-10,500\n2027-03-10,600\n2027-03-10,700\n# comment\n\n2027-03-11;480,50\n";

        $summary = $this->setup->importSamples('inn', $csv);

        self::assertSame(['samples' => 6, 'nights' => 2, 'anchors' => 2], $summary);
        $anchors = new MarketAnchorRepository($this->db, $this->clock);
        $anchor = $anchors->find('inn', Days::date('2027-03-10'));
        self::assertSame([450.0, 500.0, 600.0, 660.0, 700.0, 5], [$anchor?->p25, $anchor?->p50, $anchor?->p75, $anchor?->p90, $anchor?->max, $anchor?->n]);
        self::assertSame(AnchorOrigin::Measured, $anchor?->origin);

        $this->clock->advance(86400);
        $this->setup->importSamples('inn', "2027-03-10,900\n");
        self::assertSame(900.0, $anchors->find('inn', Days::date('2027-03-10'))?->p50, 'each paste is a full picture of its nights: a newer one replaces the anchor');
    }

    public function testPricesForAnUnknownPropertyAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->setup->importSamples('nowhere', "2027-03-10,400\n");
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badCsv(): iterable
    {
        yield 'nothing' => ["date,price\n\n", 'No price'];
        yield 'date' => ["2027-03-10,400\n10/03/2027,400", 'Line 2: the date'];
        yield 'price' => ["2027-03-10,abc", 'Line 1: the price'];
        yield 'zero' => ["2027-03-10,0", 'Line 1: the price'];
        yield 'fields' => ["2027-03-10,400,extra", 'Line 1: write exactly'];
        yield 'decimal comma with commas' => ["2027-03-10,480,50", 'Line 1: write exactly'];
    }

    #[DataProvider('badCsv')]
    public function testPastedPricesThatCannotBeReadNameTheLine(string $csv, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        SampleCsv::parse($csv);
    }

    public function testTooManyLinesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SampleCsv::parse(str_repeat("2027-03-10,400\n", SampleCsv::MAX_LINES + 1));
    }

    public function testTabSeparatedPricesWithADecimalCommaAreRead(): void
    {
        self::assertSame([['night' => '2027-03-10', 'price' => 480.5]], array_map(
            static fn (array $row): array => ['night' => Days::key($row['night']), 'price' => $row['price']],
            SampleCsv::parse("2027-03-10\t480,50")
        ));
    }
}
