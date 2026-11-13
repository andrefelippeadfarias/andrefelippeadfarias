<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Verify;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Ritmo\Domain\Write\Change\UndoAction;
use Ritmo\Domain\Write\Change\UndoKind;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\ThirdParty\Observation;
use Ritmo\Infrastructure\Db\Migration\Migrations;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\Repository\ChangeLogRepository;
use Ritmo\Infrastructure\Db\Repository\NightObservationRepository;
use Ritmo\Infrastructure\Db\Repository\PlanApprovalRepository;
use Ritmo\Infrastructure\Db\Schema\SchemaInspector;
use Ritmo\Infrastructure\Store\ArrayStore;

/**
 * The migration (8) and the repositories of the confirmation and approval area.
 */
final class StorageTest extends TestCase
{
    public function testTheMigrationAddsItsColumnsAndTablesAndRunsAgainHarmlessly(): void
    {
        $db = VerifyDatabase::create();
        $inspector = new SchemaInspector($db);

        foreach (['plan_approvals', 'night_observations', 'detected_freezes', 'wave_holds'] as $table) {
            self::assertTrue($inspector->tableExists($table), $table);
        }
        $columns = $inspector->columns('change_log');
        self::assertContains('undo_of', $columns);
        self::assertContains('reached_channel_at', $inspector->columns('step_ledger'));
        $again = (new Migrator($db, new ArrayStore(), Migrations::all()))->migrate();
        self::assertContains(8, $again, 'every step runs again from version 0');
        self::assertSame($columns, $inspector->columns('change_log'), 'and changes nothing');
    }

    public function testAChangeRecordComesBackAsItWasStored(): void
    {
        $repository = new ChangeLogRepository(VerifyDatabase::create());
        $record = new ChangeRecord('inn', Provider::Pricing, 'Deluxe', Days::date('2027-03-10'), ChangeField::FLOOR_PCT, -20, -29, new UndoAction(UndoKind::WriteFields, new NightFields([ChangeField::FLOOR_PCT => -20])), 'user:3', new DateTimeImmutable('2027-03-03T12:00:00Z'), 800.0, 710.0, 'pace_step', 'Pace step', 'order-1', 21, 'g1', null, 'live');

        $id = $repository->record($record);
        $back = $repository->byIds([$id])[0];

        self::assertEquals(new ChangeRecord('inn', Provider::Pricing, 'Deluxe', Days::date('2027-03-10'), ChangeField::FLOOR_PCT, -20, -29, $record->undo, 'user:3', $record->createdAt, 800.0, 710.0, 'pace_step', 'Pace step', 'order-1', 21, 'g1', null, 'live', $id), $back);
        self::assertSame([$id], array_map(static fn (ChangeRecord $r): ?int => $r->id, $repository->forRun(21)));
        self::assertSame([$id], array_map(static fn (ChangeRecord $r): ?int => $r->id, $repository->latest('inn', 10)));
    }

    public function testAnObservationIsStoredOnlyWhenItsValuesChange(): void
    {
        $repository = new NightObservationRepository(VerifyDatabase::create());
        $read = static fn (int $floor, string $at): Observation => new Observation(Provider::Pricing, 'Deluxe', Days::date('2027-03-10'), new NightFields([ChangeField::FLOOR_PCT => $floor]), new DateTimeImmutable($at));

        self::assertSame(1, $repository->recordChanged('inn', [$read(-20, '2027-03-03T10:00:00Z')]));
        self::assertSame(0, $repository->recordChanged('inn', [$read(-20, '2027-03-03T12:00:00Z')]));
        self::assertSame(1, $repository->recordChanged('inn', [$read(-25, '2027-03-03T14:00:00Z')]));
        self::assertSame(-25, $repository->latest('inn', Days::date('2027-03-10'), Days::date('2027-03-10'))['pricing|Deluxe|2027-03-10']->fields->get(ChangeField::FLOOR_PCT));
        self::assertSame(2, $repository->purgeBefore('inn', Days::date('2027-03-11')));
    }

    public function testOnlyOneDecisionWinsOnARequest(): void
    {
        $repository = new PlanApprovalRepository(VerifyDatabase::create());
        $at = new DateTimeImmutable('2027-03-03T12:00:00Z');
        $id = $repository->create(1, 'inn', 'plan', str_repeat('a', 64), Days::date('2027-03-03'), null, $at, $at->modify('+6 hours'), [], $at);

        self::assertTrue($repository->decide($id, 'approved', [], [], 3, 'ritmo_approve', $at));
        self::assertFalse($repository->decide($id, 'rejected', [], [], 4, 'ritmo_approve', $at));
        self::assertFalse($repository->close($id, 'expired', 'late'));
        self::assertSame('approved', $repository->find($id)['status']);
    }
}
