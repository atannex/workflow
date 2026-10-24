<?php

namespace Atannex\Workflow\Tests\Feature;

use Atannex\Workflow\Definition\State;
use Atannex\Workflow\Definition\TaskSpec;
use Atannex\Workflow\Definition\Transition;
use Atannex\Workflow\Definition\WorkflowDefinition;
use Atannex\Workflow\Engine\WorkflowEngine;
use Atannex\Workflow\Events\TaskAssigned;
use Atannex\Workflow\Events\TaskOverdue;
use Atannex\Workflow\Exceptions\TaskNotAllowed;
use Atannex\Workflow\Models\WorkflowTask;
use Atannex\Workflow\Tests\Fixtures\Article;
use Atannex\Workflow\Tests\TestCase;
use Atannex\Workflow\WorkflowRegistry;
use Illuminate\Support\Facades\Event;

class SchedulingTest extends TestCase
{
    public function test_a_scheduled_subject_goes_live_when_its_time_comes(): void
    {
        $editor = $this->user('Ebot', ['editor']);
        $article = Article::query()->create(['title' => 'X']);
        $article->update(['status' => 'approved']);

        $article->workflow()->apply('schedule', $editor, ['input' => ['publish_at' => now()->addHour()->toDateTimeString()]]);

        $instance = $article->workflow()->instance();
        $this->assertSame('scheduled', $instance->state);
        $this->assertSame('go_live', $instance->timer_transition);
        $this->assertFalse($article->workflow()->can('go_live', $editor), 'system transitions are not for people');

        $this->artisan('workflow:tick')->assertSuccessful();
        $this->assertSame('scheduled', $article->workflow()->state());

        // Rescheduling outside the engine moves the timer.
        $article->refresh()->update(['published_at' => now()->addHours(3)]);
        $this->assertEqualsWithDelta(now()->addHours(3)->timestamp, $article->workflow()->instance()->timer_at->timestamp, 2);

        $this->travel(4)->hours();
        $report = app(WorkflowEngine::class)->tick();

        $this->assertSame(1, $report['timers']);
        $this->assertSame('published', $article->workflow()->state());
        $this->assertSame('system', $article->workflow()->history()->last()->actor_kind);
    }

    public function test_overdue_tasks_escalate_once(): void
    {
        Event::fake([TaskOverdue::class, TaskAssigned::class]);

        $author = $this->user('Ayuk');
        $chief = $this->user('Chief', ['chief']);
        $article = Article::query()->create(['title' => 'X', 'author_id' => $author->id]);
        $article->workflow()->apply('submit', $author);

        $this->travel(25)->hours();
        $report = app(WorkflowEngine::class)->tick();
        $again = app(WorkflowEngine::class)->tick();

        $this->assertSame(1, $report['overdue']);
        $this->assertSame(1, $report['escalated']);
        $this->assertSame(0, $again['overdue']);
        Event::assertDispatchedTimes(TaskOverdue::class, 1);

        $escalated = WorkflowTask::query()->for($chief)->sole();
        $this->assertSame(1, $escalated->escalation_level);

        // Completing either copy completes the task.
        app(WorkflowEngine::class)->complete($escalated, $chief, 'Looked at it');
        $this->assertSame(0, WorkflowTask::query()->open()->count());
    }

    public function test_tasks_can_be_claimed_released_and_reassigned(): void
    {
        $author = $this->user('Ayuk');
        $first = $this->user('Ebot', ['editor']);
        $second = $this->user('Fon', ['editor']);
        $article = Article::query()->create(['title' => 'X', 'author_id' => $author->id]);
        $article->workflow()->apply('submit', $author);

        $engine = app(WorkflowEngine::class);
        $task = WorkflowTask::query()->open()->sole();

        $engine->claim($task, $first);
        $this->assertSame(1, WorkflowTask::query()->for($first)->count());
        $this->assertSame(0, WorkflowTask::query()->for($second)->count());

        try {
            $engine->claim($task->fresh(), $second);
            $this->fail('A claimed task is taken.');
        } catch (TaskNotAllowed) {
        }

        $engine->release($task->fresh(), $first);
        $this->assertSame(1, WorkflowTask::query()->for($second)->count());

        $copy = $engine->reassign($task->fresh(), $second, $first);
        $this->assertSame(['user', (string) $second->id], [$copy->assignee_type, $copy->assignee]);
        $this->assertSame(WorkflowTask::CANCELLED, $task->fresh()->status);
        $this->assertSame(0, WorkflowTask::query()->for($first)->count());
    }

    public function test_parallel_tasks_join_with_an_automatic_transition(): void
    {
        app(WorkflowRegistry::class)->register(
            WorkflowDefinition::make('legal')
                ->for(Article::class)
                ->autoStart(false)
                ->state('checks', fn (State $s) => $s
                    ->task('legal', fn (TaskSpec $t) => $t->assign('role:lawyer'))
                    ->task('facts', fn (TaskSpec $t) => $t->assign('role:checker')))
                ->state('cleared', fn (State $s) => $s->final())
                ->transition('clear', fn (Transition $t) => $t->from('checks')->to('cleared')->automatic()->guard('tasks-complete'))
        );

        $lawyer = $this->user('Law', ['lawyer']);
        $checker = $this->user('Fact', ['checker']);
        $article = Article::query()->create(['title' => 'X']);
        $article->workflow('legal')->start();

        $engine = app(WorkflowEngine::class);
        $engine->complete(WorkflowTask::query()->for($lawyer)->sole(), $lawyer);
        $this->assertSame('checks', $article->workflow('legal')->state());

        $engine->complete(WorkflowTask::query()->for($checker)->sole(), $checker);
        $this->assertSame('cleared', $article->workflow('legal')->state());

        // The editorial workflow runs alongside, untouched.
        $this->assertSame('draft', $article->workflow('editorial')->state());
    }

    public function test_deadline_transitions_and_automatic_loops_are_bounded(): void
    {
        app(WorkflowRegistry::class)->register(
            WorkflowDefinition::make('ping')
                ->for(Article::class)
                ->autoStart(false)
                ->state('a', fn (State $s) => $s->task('wait', fn (TaskSpec $t) => $t->assign('role:x')->due('1 hour')->whenOverdue('expire')))
                ->state('b')
                ->state('expired', fn (State $s) => $s->final())
                ->transition('expire', fn (Transition $t) => $t->from('a')->to('expired')->system())
                ->transition('loop_ab', fn (Transition $t) => $t->from('b')->to('a')->automatic()->guard(fn ($c) => $c->subject->title === 'loop'))
                ->transition('loop_ba', fn (Transition $t) => $t->from('a')->to('b')->automatic()->guard(fn ($c) => $c->subject->title === 'loop'))
        );

        $article = Article::query()->create(['title' => 'calm']);
        $article->workflow('ping')->start();

        $this->travel(2)->hours();
        app(WorkflowEngine::class)->tick();
        $this->assertSame('expired', $article->workflow('ping')->state());

        $looping = Article::query()->create(['title' => 'loop']);
        $looping->workflow('ping')->start();

        $this->assertLessThanOrEqual(12, $looping->workflow('ping')->history()->count());
    }
}
