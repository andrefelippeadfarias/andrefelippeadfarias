<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Db;

use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Unit\Infrastructure\Support\MigratedDatabase;

final class ConfigurationRepositoriesTest extends TestCase
{
    private PdoDatabase $db;

    private MovableClock $clock;

    protected function setUp(): void
    {
        $this->db = MigratedDatabase::create();
        $this->clock = new MovableClock('2026-11-11T12:00:00Z');
    }

    public function testAPropertyIsSavedFoundAndReplaced(): void
    {
        $properties = new PropertyRepository($this->db, $this->clock);
        $inn = new Property('inn-1', 'Harbour Inn', 'America/Sao_Paulo', 'BRL', PropertyMode::Shadow, [30 => 55.5, 7 => 80.0], 'premium');

        $properties->save($inn);

        self::assertEquals($inn, $properties->find('inn-1'));
        self::assertSame([30 => 55.5, 7 => 80.0], $properties->find('inn-1')?->occupancyGoals);
        self::assertNull($properties->find('other'));

        $this->clock->advance(3600);
        $properties->save(new Property('inn-1', 'Harbour Inn & Spa', 'America/Sao_Paulo', 'BRL'));

        self::assertSame('Harbour Inn & Spa', $properties->find('inn-1')?->name);
        self::assertSame([], $properties->find('inn-1')?->occupancyGoals);
        self::assertCount(1, $properties->all());
        self::assertSame(
            ['created_at' => '2026-11-11 12:00:00', 'updated_at' => '2026-11-11 13:00:00'],
            $this->db->selectRow('SELECT created_at, updated_at FROM wp_ritmo_properties WHERE code = ?', ['inn-1'])
        );
    }

    public function testPropertiesAreListedInTheOrderTheyWereCreated(): void
    {
        $properties = new PropertyRepository($this->db, $this->clock);
        $properties->save(new Property('zeta', 'Zeta', 'UTC', 'USD'));
        $properties->save(new Property('alpha', 'Alpha', 'UTC', 'USD'));

        self::assertSame(['zeta', 'alpha'], array_map(static fn (Property $property): string => $property->id, $properties->all()));
    }

    public function testTheModeOfAPropertyChangesAloneAndOnlyForAKnownProperty(): void
    {
        $properties = new PropertyRepository($this->db, $this->clock);
        $properties->save(new Property('inn-1', 'Inn', 'UTC', 'USD', PropertyMode::Shadow, [14 => 60.0]));

        $properties->setMode('inn-1', PropertyMode::Approval);

        self::assertSame(PropertyMode::Approval, $properties->find('inn-1')?->mode);
        self::assertSame([14 => 60.0], $properties->find('inn-1')?->occupancyGoals);

        $this->expectException(OutOfBoundsException::class);
        $properties->setMode('missing', PropertyMode::Auto);
    }

    public function testCodesThatDifferOnlyInCaseAreRefusedAndLookupsAreExact(): void
    {
        $properties = new PropertyRepository($this->db, $this->clock);
        $properties->save(new Property('Inn', 'Inn', 'UTC', 'USD'));

        self::assertNull($properties->find('inn'));
        $properties->delete('inn');
        self::assertNotNull($properties->find('Inn'));

        try {
            $properties->save(new Property('inn', 'Other', 'UTC', 'USD'));
            self::fail('A code that differs only in case must be refused.');
        } catch (InvalidArgumentException $refusal) {
            self::assertStringContainsString('differ only in case', $refusal->getMessage());
        }
        self::assertCount(1, $properties->all());

        $properties->delete('Inn');
        self::assertSame([], $properties->all());
    }

    public function testRoomTypesAreSavedPerPropertyInOrder(): void
    {
        $rooms = new RoomTypeRepository($this->db, $this->clock);
        $suite = new RoomType('inn-1', 'suite', 'Garden Suite', 2, '901', 'L-77', 420.0, 310.5, 990.0, 300.0, ['premium', 'tub']);
        $double = new RoomType('inn-1', 'double', 'Double', 5, '', '', 250.0, 180.0, 600.0, 170.0);
        $loft = new RoomType('inn-2', 'loft', 'Loft', 1, '902', 'L-78', 500.0, 400.0, 1200.0, 380.0);

        $rooms->save($suite);
        $rooms->save($double);
        $rooms->save($loft);

        self::assertEquals($suite, $rooms->find('suite'));
        self::assertEquals([$suite, $double], $rooms->forProperty('inn-1'));
        self::assertEquals([$suite, $double, $loft], $rooms->all());
        self::assertSame([], $rooms->forProperty('inn-3'));
        self::assertNull($rooms->find('SUITE'));

        $rooms->save(new RoomType('inn-1', 'suite', 'Garden Suite', 3, '901', 'L-77', 430.0, 310.5, 990.0, 300.0));
        self::assertSame(3, $rooms->find('suite')?->units);
        self::assertSame([], $rooms->find('suite')?->tags);
        self::assertSame(['suite', 'double'], array_map(static fn (RoomType $room): string => $room->code, $rooms->forProperty('inn-1')), 'replacing keeps the position');

        $rooms->delete('double');
        self::assertCount(1, $rooms->forProperty('inn-1'));
    }

    public function testThePmsMapListsOnlyMappedRooms(): void
    {
        $rooms = new RoomTypeRepository($this->db, $this->clock);
        $rooms->save(new RoomType('inn-1', 'suite', 'Suite', 2, '901', 'L-77', 420.0, 310.0, 990.0, 300.0));
        $rooms->save(new RoomType('inn-1', 'double', 'Double', 5, '', '', 250.0, 180.0, 600.0, 170.0));
        $rooms->save(new RoomType('inn-2', 'loft', 'Loft', 1, '902', '', 500.0, 400.0, 1200.0, 380.0));

        self::assertSame(['901' => 'suite', '902' => 'loft'], $rooms->pmsMap());
    }

    public function testARoomRowChangedByHandIntoAnInvalidRoomIsLeftOutAndNamed(): void
    {
        $rooms = new RoomTypeRepository($this->db, $this->clock);
        $rooms->save(new RoomType('inn', 'STD', 'Standard', 2, '1', 'pl-1', 600.0, 400.0, 1200.0, 400.0));
        $rooms->save(new RoomType('inn', 'GDN', 'Garden', 2, '2', 'pl-2', 600.0, 400.0, 1200.0, 400.0));
        $this->db->update('room_types', ['tags' => '["peer<x>"]'], ['code' => 'STD']);

        self::assertSame(['GDN'], array_map(static fn (RoomType $r): string => $r->code, $rooms->forProperty('inn')));
        self::assertSame(['GDN'], array_map(static fn (RoomType $r): string => $r->code, $rooms->all()));
        self::assertNull($rooms->find('STD'));
        self::assertSame(['STD'], $rooms->unreadable());
    }

    public function testARoomReferenceLongerThanItsColumnIsRefusedNotCut(): void
    {
        $rooms = new RoomTypeRepository($this->db, $this->clock);

        $this->expectException(InvalidArgumentException::class);
        $rooms->save(new RoomType('inn-1', 'suite', 'Suite', 2, str_repeat('9', 65), '', 420.0, 310.0, 990.0, 300.0));
    }

    public function testRoomCodesThatDifferOnlyInCaseAreRefused(): void
    {
        $rooms = new RoomTypeRepository($this->db, $this->clock);
        $rooms->save(new RoomType('inn-1', 'Suite', 'Suite', 2, '', '', 420.0, 310.0, 990.0, 300.0));

        $this->expectException(InvalidArgumentException::class);
        $rooms->save(new RoomType('inn-1', 'suite', 'Suite', 2, '', '', 420.0, 310.0, 990.0, 300.0));
    }

    public function testARuleSetIsSavedAsANewVersionAndReadBackIdentical(): void
    {
        $repository = new RuleSetRepository($this->db, $this->clock);
        self::assertNull($repository->current());
        self::assertNull($repository->currentVersion());

        $defaults = RuleSet::defaults();
        $parity = Fixtures::parityRules();

        self::assertSame(1, $repository->save($defaults, 'Initial rules', 7));
        $this->clock->advance(60);
        self::assertSame(2, $repository->save($parity, 'Imported preset'));

        self::assertSame(2, $repository->currentVersion());
        self::assertSame($parity->hash(), $repository->current()?->hash());
        self::assertSame($parity->toArray(), $repository->current()?->toArray());
        self::assertSame($defaults->hash(), $repository->find(1)?->hash());
        self::assertNull($repository->find(3));
    }

    public function testSavingTheCurrentRulesAgainCreatesNoVersion(): void
    {
        $repository = new RuleSetRepository($this->db, $this->clock);
        $repository->save(RuleSet::defaults());

        self::assertSame(1, $repository->save(RuleSet::defaults(), 'nothing changed'));
        self::assertSame('1', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_rulesets'));
    }

    public function testGoingBackToOldRulesIsANewVersionAndOldVersionsNeverChange(): void
    {
        $repository = new RuleSetRepository($this->db, $this->clock);
        $repository->save(RuleSet::defaults(), 'v1');
        $stored = $this->db->select('SELECT * FROM wp_ritmo_rulesets ORDER BY version');
        $this->clock->advance(60);
        $repository->save(Fixtures::parityRules(), 'v2');
        $this->clock->advance(60);

        self::assertSame(3, $repository->save(RuleSet::defaults(), 'back to v1', 4));

        self::assertSame($stored[0], $this->db->select('SELECT * FROM wp_ritmo_rulesets ORDER BY version')[0], 'version 1 is untouched');
        self::assertSame($repository->find(1)?->hash(), $repository->find(3)?->hash());

        $history = $repository->history();
        self::assertSame([3, 2, 1], array_column($history, 'version'));
        self::assertSame(['back to v1', 'v2', 'v1'], array_column($history, 'note'));
        self::assertSame([4, 0, 0], array_column($history, 'created_by'));
        self::assertSame('2026-11-11T12:02:00+00:00', $history[0]['created_at']->format(DATE_ATOM));
        self::assertCount(1, $repository->history(1));
    }

    public function testTheRepositoryOffersNoWayToEditOrDeleteAVersion(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(RuleSetRepository::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        sort($methods);

        self::assertSame(['__construct', 'current', 'currentVersion', 'find', 'history', 'save', 'upgradeCurrent'], $methods, 'upgradeCurrent only saves a new version');

        $source = (string) file_get_contents((string) (new \ReflectionClass(RuleSetRepository::class))->getFileName());
        self::assertDoesNotMatchRegularExpression('/->(update|delete|upsert|execute)\(/', $source, 'rule set versions are only ever inserted');
    }

    /**
     * An update from M2 to M3 found rules without the sections M3 added and broke every screen
     * (review finding of the M3 correction phase): the stored rules are now completed with the
     * neutral defaults, kept as they were otherwise, and saved once as a new version.
     */
    public function testRulesStoredByAnEarlierVersionAreCompletedWithTheNeutralDefaults(): void
    {
        $m2 = Fixtures::json('presets/rules-m2.json')['rules'];
        self::assertArrayNotHasKey('write', $m2);
        $this->db->insert('rulesets', ['version' => 1, 'hash' => str_repeat('a', 64), 'rules' => json_encode($m2), 'note' => 'M2', 'created_by' => 0, 'created_at' => '2026-11-10 12:00:00']);
        $repository = new RuleSetRepository($this->db, $this->clock);

        $current = $repository->current();
        self::assertNotNull($current);
        self::assertEquals(RuleSet::defaults()->write(), $current->write(), 'a section added later takes its neutral default');
        self::assertSame(RuleSet::defaults()->toArray()['modes'], $current->toArray()['modes'], 'the new modes stay neutral (autonomous off)');
        self::assertSame(RuleSet::defaults()->plan()->horizonNights, $current->plan()->horizonNights, 'a field added later inside an old section too');
        self::assertSame($m2['ladder'], $current->toArray()['ladder'], 'what was stored is kept');

        self::assertSame(2, $repository->upgradeCurrent(), 'the completed rules are saved once as a new version');
        self::assertNull($repository->upgradeCurrent(), 'and only once');
        self::assertSame(RuleSet::fromStored($m2)->hash(), $repository->current()?->hash());
    }

    public function testCompletingStoredRulesNeverLoosensWhatWasStored(): void
    {
        $m2 = Fixtures::json('presets/rules-m2.json')['rules'];
        $m2['quota'] = ['per_listing' => 10];

        $this->expectException(\Ritmo\Domain\Config\InvalidConfig::class);
        RuleSet::fromStored($m2);
    }
}
