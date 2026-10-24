<?php

namespace Atannex\Workflow\Tests\Unit;

use Atannex\Workflow\Definition\ArrayDefinition;
use Atannex\Workflow\Definition\State;
use Atannex\Workflow\Definition\Transition;
use Atannex\Workflow\Definition\WorkflowDefinition;
use Atannex\Workflow\Exceptions\InvalidDefinition;
use Atannex\Workflow\Support\Diagram;
use Atannex\Workflow\Support\Duration;
use Atannex\Workflow\Tests\TestCase;

class DefinitionTest extends TestCase
{
    public function test_problems_are_reported(): void
    {
        $definition = WorkflowDefinition::make('broken')
            ->state('a', fn (State $s) => $s->after('1 day', 'nope'))
            ->state('island')
            ->state('end', fn (State $s) => $s->final())
            ->transition('go', fn (Transition $t) => $t->from('a', 'ghost')->to('end'))
            ->transition('bad', fn (Transition $t) => $t->from('a')->to('missing'));

        ['errors' => $errors, 'warnings' => $warnings] = $definition->problems();

        $this->assertContains('Transition [go] starts from an unknown state [ghost].', $errors);
        $this->assertContains('Transition [bad] goes to an unknown state [missing].', $errors);
        $this->assertContains('The timer in [a] uses an unknown transition [nope].', $errors);
        $this->assertContains('State [island] cannot be reached from [a].', $warnings);
        $this->assertContains('State [island] is a dead end: no transition leaves it and it is not final.', $warnings);

        $this->expectException(InvalidDefinition::class);
        $definition->assertValid();
    }

    public function test_wildcard_transitions_leave_every_non_final_state(): void
    {
        $definition = WorkflowDefinition::make('w')
            ->state('a')->state('b')->state('cancelled', fn (State $s) => $s->final())
            ->transition('next', fn (Transition $t) => $t->from('a')->to('b'))
            ->transition('cancel', fn (Transition $t) => $t->from('*')->to('cancelled'));

        $this->assertArrayHasKey('cancel', $definition->transitionsFrom('a'));
        $this->assertArrayHasKey('cancel', $definition->transitionsFrom('b'));
        $this->assertArrayNotHasKey('cancel', $definition->transitionsFrom('cancelled'));
        $this->assertSame([], $definition->problems()['errors']);
        $this->assertStringContainsString('b --> cancelled', Diagram::mermaid($definition));
    }

    public function test_array_definitions(): void
    {
        $definition = ArrayDefinition::build([
            'name' => 'leave',
            'for' => ['App\\Models\\LeaveRequest'],
            'column' => 'status',
            'states' => [
                'requested' => ['tasks' => ['decide' => ['assign' => ['role:manager'], 'due' => '2 days', 'escalate_to' => 'role:hr']]],
                'approved' => ['final' => true],
                'refused' => ['final' => true],
            ],
            'transitions' => [
                'approve' => ['from' => 'requested', 'to' => 'approved', 'guards' => ['role:manager', 'not-owner'], 'approvals' => ['required' => 2, 'reject_with' => 'refuse']],
                'refuse' => ['from' => 'requested', 'to' => 'refused', 'comment' => true],
            ],
        ])->assertValid();

        $this->assertSame('requested', $definition->initial);
        $this->assertSame(2, $definition->transitions['approve']->approval->required);
        $this->assertSame(['role:hr'], $definition->states['requested']->tasks['decide']->escalateTo);
        $this->assertTrue($definition->transitions['refuse']->requiresComment);
    }

    public function test_durations(): void
    {
        $this->assertSame(90, Duration::seconds(90));
        $this->assertSame(86400 + 7200, Duration::seconds('1 day 2 hours'));
        $this->assertSame(3600, Duration::seconds('PT1H'));
    }
}
