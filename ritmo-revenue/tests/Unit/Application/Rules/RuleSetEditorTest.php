<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Rules;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Rules\FieldChange;
use Ritmo\Application\Rules\RulesRefused;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Infrastructure\Db\Repository\EditorEventRepository;

final class RuleSetEditorTest extends TestCase
{
    public function testSavingAChangedGoalCreatesTheNextVersionClosesTheWaitingPlanAndRecordsWho(): void
    {
        $world = new RulesWorld();
        $world->approvals->waiting[RulesWorld::PROPERTY] = 64;
        self::assertSame([RulesWorld::PROPERTY => 64], $world->editor->waitingNights());
        $rules = $world->rules();
        $rules['occupancy']['goals'][7] = 65.0;

        $saved = $world->editor->save($rules, 1, $world->hash(), 7, 'Higher goal for next week', null, false);

        self::assertSame([2, 1, 1, 1, false], [$saved->version, $saved->previous, $saved->changes, $saved->closedApprovals, $saved->unchanged]);
        self::assertSame(65.0, $world->ruleSets->current()?->toArray()['occupancy']['goals'][7]);
        self::assertSame([[RulesWorld::PROPERTY, 'rules_changed']], $world->approvals->closed);
        $event = $world->events->latest(RulesWorld::PROPERTY, [EditorEventRepository::RULES_VERSION])[0];
        self::assertSame(['1', '2', 'user:7', 'Higher goal for next week'], [$event['from'], $event['to'], $event['actor'], $event['reason']]);
        self::assertSame(['changes' => 1, 'source' => 'editor', 'restored_from' => null, 'safety' => [], 'confirmed' => ''], $event['details']);
    }

    public function testEqualRulesSaveNothing(): void
    {
        $world = new RulesWorld();

        $saved = $world->editor->save($world->rules(), 1, $world->hash(), 7, '', null, false);

        self::assertTrue($saved->unchanged);
        self::assertSame(1, $world->ruleSets->currentVersion());
        self::assertSame([], $world->events->latest(RulesWorld::PROPERTY, [EditorEventRepository::RULES_VERSION]));
    }

    public function testInvalidRulesAreRefusedWithEveryErrorAndNoVersion(): void
    {
        $world = new RulesWorld();
        $rules = $world->rules();
        $rules['occupancy']['goals'][7] = 150.0;
        $rules['price_bands']['items'] = [['id' => 'b1', 'label' => 'Band', 'enabled' => true, 'property' => 'inn-1', 'room' => 'room-z', 'night_types' => ['weekday'], 'price_plan' => 'sent', 'min_amount' => 100.0, 'max_amount' => 900.0, 'max_days_out' => 60, 'skip_on_strategy_nights' => true]];

        try {
            $world->editor->save($rules, 1, $world->hash(), 7, '', null, false);
            self::fail('invalid rules');
        } catch (InvalidConfig $invalid) {
            self::assertContains('occupancy.goals.7', array_map(static fn (ValidationError $e): string => $e->path, $invalid->errors()));
        }
        $rules['occupancy']['goals'][7] = 60.0;
        try {
            $world->editor->save($rules, 1, $world->hash(), 7, '', null, false);
            self::fail('unknown room');
        } catch (InvalidConfig $invalid) {
            self::assertSame(['price_bands.items.0.room'], array_map(static fn (ValidationError $e): string => $e->path, $invalid->errors()));
        }
        self::assertSame(1, $world->ruleSets->currentVersion());
    }

    public function testASafetyFieldNeedsTheCapabilityAndTheTypedNewValue(): void
    {
        $world = new RulesWorld();
        $rules = $world->rules();
        $rules['floors']['fraction_of_reference_min'] = 0.7;

        foreach ([[null, false, RulesRefused::SAFETY_NOT_ALLOWED], ['75', true, RulesRefused::CONFIRMATION], ['', true, RulesRefused::CONFIRMATION]] as [$typed, $may, $reason]) {
            try {
                $world->editor->save($rules, 1, $world->hash(), 7, '', $typed, $may);
                self::fail($reason);
            } catch (RulesRefused $refused) {
                self::assertSame($reason, $refused->reason);
            }
        }
        self::assertSame(1, $world->ruleSets->currentVersion());

        $saved = $world->editor->save($rules, 1, $world->hash(), 7, '', ' 70 ', true);

        self::assertSame(2, $saved->version);
        self::assertSame(0.7, $world->ruleSets->current()?->toArray()['floors']['fraction_of_reference_min'], 'typed 70 stores exactly 0.7');
        $details = $world->events->latest(RulesWorld::PROPERTY, [EditorEventRepository::RULES_VERSION])[0]['details'];
        self::assertSame([['floors.fraction_of_reference_min'], '70'], [$details['safety'], $details['confirmed']]);
    }

    public function testADraftOfAnOlderVersionIsMergedAndAConflictRefusesTheSave(): void
    {
        $world = new RulesWorld();
        $baseHash = $world->hash();
        $draftB = $world->rules();
        $draftB['occupancy']['goals'][7] = 70.0;
        $draftA = $world->rules();
        $draftA['occupancy']['goals'][15] = 55.0;
        $world->editor->save($draftA, 1, $baseHash, 1, '', null, false);

        $saved = $world->editor->save($draftB, 1, $baseHash, 2, '', null, false);

        self::assertSame(3, $saved->version);
        $goals = $world->ruleSets->current()?->toArray()['occupancy']['goals'];
        self::assertSame([70.0, 55.0], [$goals[7], $goals[15]], 'version 3 holds both changes');

        $draftC = $world->rules();
        $draftC['occupancy']['goals'][7] = 80.0;
        $draftD = $world->rules();
        $hash3 = $world->hash();
        $draftD['occupancy']['goals'][7] = 75.0;
        $world->editor->save($draftC, 3, $hash3, 1, '', null, false);
        try {
            $world->editor->save($draftD, 3, $hash3, 2, '', null, false);
            self::fail('conflict');
        } catch (RulesRefused $refused) {
            self::assertSame(RulesRefused::CONFLICT, $refused->reason);
            self::assertSame([['path' => 'occupancy.goals.7', 'key' => 'occupancy.goals.7', 'mine' => 75.0, 'theirs' => 80.0]], $refused->params['conflicts']);
        }
        self::assertSame(4, $world->ruleSets->currentVersion());
    }

    public function testANoteWithGuestDataIsRefused(): void
    {
        $world = new RulesWorld();
        $this->expectException(RulesRefused::class);
        $world->editor->save($world->rules(), 1, $world->hash(), 7, 'asked by guest@example.com', null, false);
    }

    public function testRestoringAnOldVersionMakesANewVersionWithItsHash(): void
    {
        $world = new RulesWorld();
        $hash1 = $world->hash();
        $rules = $world->rules();
        $rules['occupancy']['goals'][7] = 65.0;
        $world->editor->save($rules, 1, $hash1, 7, '', null, false);

        $restored = $world->history->rules(1)->toArray();
        $saved = $world->editor->save($restored, 2, $world->hash(), 7, 'Back to the defaults', null, false, 'restore', 1);

        self::assertSame(3, $saved->version);
        self::assertSame($hash1, $world->ruleSets->current()?->hash());
        $page = $world->history->page(1);
        self::assertSame(3, $page['total']);
        self::assertSame([[3, 'restore', 1, 1, true], [2, 'editor', null, 1, false], [1, '', null, null, false]], array_map(static fn (array $r): array => [$r['version'], $r['source'], $r['restored_from'], $r['changes'], $r['in_force']], $page['rows']));
        self::assertSame(['occupancy.goals.7'], array_map(static fn (FieldChange $c): string => $c->path, $world->history->compare(1, 2)));
        $this->expectException(RulesRefused::class);
        $world->history->rules(99);
    }
}
