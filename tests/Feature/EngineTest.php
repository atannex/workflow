<?php

namespace Atannex\Workflow\Tests\Feature;

use Atannex\Workflow\Engine\WorkflowEngine;
use Atannex\Workflow\Events\StateEntered;
use Atannex\Workflow\Events\TaskAssigned;
use Atannex\Workflow\Events\Transitioned;
use Atannex\Workflow\Events\Transitioning;
use Atannex\Workflow\Events\WorkflowCompleted;
use Atannex\Workflow\Exceptions\StaleState;
use Atannex\Workflow\Exceptions\TransitionNotAllowed;
use Atannex\Workflow\Models\WorkflowHistory;
use Atannex\Workflow\Models\WorkflowInstance;
use Atannex\Workflow\Models\WorkflowTask;
use Atannex\Workflow\Support\Actor;
use Atannex\Workflow\Tests\Fixtures\Article;
use Atannex\Workflow\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EngineTest extends TestCase
{
    public function test_creating_a_subject_starts_its_workflow_and_mirrors_the_state(): void
    {
        $article = Article::query()->create(['title' => 'Hello']);

        $this->assertSame('draft', $article->workflow()->state());
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertSame(WorkflowHistory::START, $article->workflow()->history()->first()->transition);
    }

    public function test_existing_subjects_start_in_the_inferred_state(): void
    {
        $article = Article::withoutEvents(fn () => Article::query()->create(['title' => 'Old', 'published_at' => now()->subYear()]));

        $this->assertSame('published', $article->workflow()->state());
        $this->assertSame(WorkflowInstance::COMPLETED, $article->workflow()->instance()->status);
    }

    public function test_a_full_happy_path_with_history_and_tasks(): void
    {
        $author = $this->user('Ayuk');
        $editor = $this->user('Ebot', ['editor']);
        $article = Article::query()->create(['title' => 'Budget', 'author_id' => $author->id]);

        $article->workflow()->apply('submit', $author, ['comment' => 'Ready']);
        $this->assertSame('review', $article->fresh()->status);

        $task = WorkflowTask::query()->open()->sole();
        $this->assertSame(['role', 'editor'], [$task->assignee_type, $task->assignee]);
        $this->assertTrue($task->isFor($editor));
        $this->assertFalse($task->isFor($author));
        $this->assertNotNull($task->due_at);

        $article->workflow()->apply('approve', $editor);
        $this->assertSame('approved', $article->workflow()->state());
        $this->assertSame(WorkflowTask::COMPLETED, $task->fresh()->status);
        $this->assertSame($editor->id, (int) $task->fresh()->completed_by);

        $article->workflow()->apply('publish', $editor);
        $article->refresh();

        $this->assertSame('published', $article->status);
        $this->assertNotNull($article->published_at);
        $this->assertSame(
            [WorkflowHistory::START, 'submit', 'approve', 'publish'],
            $article->workflow()->history()->pluck('transition')->all(),
        );
        $this->assertSame('Ready', $article->workflow()->history()[1]->comment);
    }

    public function test_guards_explain_why_a_transition_is_refused(): void
    {
        $author = $this->user('Ayuk', ['editor']);
        $stranger = $this->user('Tabe');
        $article = Article::query()->create(['title' => '', 'author_id' => $author->id]);

        $check = $article->workflow()->check('submit', $stranger);
        $this->assertFalse($check->allowed);
        $this->assertContains('Only the owner can do this.', $check->reasons);
        $this->assertStringContainsString('title', $check->message());

        $article->update(['title' => 'Now filled']);
        $article->workflow()->apply('submit', $author);

        // Separation of duties: an editor cannot approve their own story.
        $this->assertFalse($article->workflow()->can('approve', $author));

        try {
            $article->workflow()->apply('approve', $author);
            $this->fail('Approving own work should be refused.');
        } catch (TransitionNotAllowed $e) {
            $this->assertSame(['You cannot do this on your own work.'], $e->reasons());
        }

        $this->assertFalse($article->workflow()->can('publish', $author), 'publish does not leave review');
    }

    public function test_available_lists_only_what_the_user_may_do(): void
    {
        $author = $this->user('Ayuk');
        $editor = $this->user('Ebot', ['editor']);
        $article = Article::query()->create(['title' => 'X', 'author_id' => $author->id]);

        $this->assertSame(['submit'], array_keys($article->workflow()->available($author)));
        $this->assertSame([], array_keys($article->workflow()->available($editor)));

        $article->workflow()->apply('submit', $author);

        $this->assertSame(['request_changes', 'approve'], array_keys($article->workflow()->available($editor)));
        $this->assertSame([], array_keys($article->workflow()->available($author)));
    }

    public function test_required_comment_and_input_are_validated(): void
    {
        $author = $this->user('Ayuk');
        $editor = $this->user('Ebot', ['editor']);
        $article = Article::query()->create(['title' => 'X', 'author_id' => $author->id]);
        $article->workflow()->apply('submit', $author);

        try {
            $article->workflow()->apply('request_changes', $editor);
            $this->fail('A comment is required.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('comment', $e->errors());
        }

        $article->workflow()->apply('approve', $editor);

        $this->expectException(ValidationException::class);
        $article->workflow()->apply('schedule', $editor, ['input' => ['publish_at' => now()->subDay()->toDateTimeString()]]);
    }

    public function test_revision_loop_hands_the_work_back_to_the_owner(): void
    {
        $author = $this->user('Ayuk');
        $editor = $this->user('Ebot', ['editor']);
        $article = Article::query()->create(['title' => 'X', 'author_id' => $author->id]);

        $article->workflow()->apply('submit', $author);
        $article->workflow()->apply('request_changes', $editor, ['comment' => 'Source the numbers']);

        $task = WorkflowTask::query()->open()->sole();
        $this->assertSame(['user', (string) $author->id], [$task->assignee_type, $task->assignee]);
        $this->assertSame(1, WorkflowTask::query()->for($author)->count());

        $article->workflow()->apply('submit', $author);
        $this->assertSame('review', $article->workflow()->state());
        $this->assertSame(0, WorkflowTask::query()->for($author)->count());
        $this->assertSame(1, WorkflowTask::query()->for($editor)->count());
    }

    public function test_stale_state_is_detected(): void
    {
        $author = $this->user('Ayuk');
        $article = Article::query()->create(['title' => 'X', 'author_id' => $author->id]);
        $article->workflow()->apply('submit', $author);

        $this->expectException(StaleState::class);
        $article->workflow()->apply('submit', $author, ['expect' => 'draft']);
    }

    public function test_a_failing_effect_rolls_everything_back_and_dispatches_nothing(): void
    {
        $author = $this->user('Ayuk');
        $article = Article::query()->create(['title' => 'X', 'author_id' => $author->id]);

        Event::fake([Transitioned::class, StateEntered::class, TaskAssigned::class]);
        Event::listen(Transitioning::class, fn () => throw new RuntimeException('Mail server down'));

        try {
            $article->workflow()->apply('submit', $author);
            $this->fail('Expected the listener exception.');
        } catch (RuntimeException) {
        }

        $this->assertSame('draft', $article->workflow()->state());
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertSame(0, WorkflowTask::query()->count());
        $this->assertSame(1, WorkflowHistory::query()->count());
        Event::assertNotDispatched(Transitioned::class);
        Event::assertNotDispatched(TaskAssigned::class);
    }

    public function test_listeners_can_veto_a_transition(): void
    {
        $author = $this->user('Ayuk');
        $article = Article::query()->create(['title' => 'X', 'author_id' => $author->id]);

        Event::listen(Transitioning::class, fn (Transitioning $event) => $event->block('Embargoed until Monday.'));

        try {
            $article->workflow()->apply('submit', $author);
            $this->fail('Expected a veto.');
        } catch (TransitionNotAllowed $e) {
            $this->assertSame(['Embargoed until Monday.'], $e->reasons());
        }

        $this->assertSame('draft', $article->workflow()->state());
    }

    public function test_events_are_dispatched_after_commit(): void
    {
        Event::fake([Transitioned::class, WorkflowCompleted::class, TaskAssigned::class]);

        $editor = $this->user('Ebot', ['editor']);
        $article = Article::query()->create(['title' => 'X']);
        $article->workflow()->start();

        app(WorkflowEngine::class)->sync($article->workflow()->instance(), $article, 'approved', Actor::system());
        $article->workflow()->apply('publish', $editor);

        Event::assertDispatched(Transitioned::class, fn (Transitioned $e) => $e->entry->transition === 'publish' && $e->context->to === 'published');
        Event::assertDispatched(WorkflowCompleted::class);
    }

    public function test_outside_changes_to_the_mirror_column_are_reconciled_and_audited(): void
    {
        $editor = $this->user('Ebot', ['editor']);
        $article = Article::query()->create(['title' => 'X']);

        $this->actingAs($editor);
        $article->update(['status' => 'approved']);

        $this->assertSame('approved', $article->workflow()->state());
        $entry = $article->workflow()->history()->last();
        $this->assertSame(WorkflowHistory::SYNC, $entry->transition);
        $this->assertSame('sync', $entry->actor_kind);
        $this->assertSame($editor->id, (int) $entry->actor_id);

        // Values that are not states are left alone.
        $article->update(['status' => 'nonsense']);
        $this->assertSame('approved', $article->workflow()->state());
    }

    public function test_trashing_suspends_and_restoring_resumes(): void
    {
        $author = $this->user('Ayuk');
        $article = Article::query()->create(['title' => 'X', 'author_id' => $author->id]);
        $article->workflow()->apply('submit', $author);

        $article->delete();
        $instance = WorkflowInstance::query()->sole();
        $this->assertSame(WorkflowInstance::SUSPENDED, $instance->status);
        $this->assertSame(0, WorkflowTask::query()->open()->count());
        $this->assertFalse(app(WorkflowEngine::class)->can($instance, 'approve', $this->user('Ebot', ['editor'])));

        $article->restore();
        $this->assertSame(WorkflowInstance::ACTIVE, $instance->fresh()->status);
        $this->assertSame(1, WorkflowTask::query()->open()->count());

        $article->forceDelete();
        $this->assertSame(0, WorkflowInstance::query()->count());
        $this->assertSame(0, WorkflowHistory::query()->count());
    }

    public function test_a_final_state_can_be_left_again(): void
    {
        $editor = $this->user('Ebot', ['editor']);
        $article = Article::withoutEvents(fn () => Article::query()->create(['title' => 'Old', 'published_at' => now()->subDay()]));

        $article->workflow()->apply('retract', $editor);

        $instance = $article->workflow()->instance();
        $this->assertSame('draft', $instance->state);
        $this->assertSame(WorkflowInstance::ACTIVE, $instance->status);
        $this->assertNull($instance->completed_at);
    }

    public function test_cancelled_instances_refuse_every_transition(): void
    {
        $author = $this->user('Ayuk');
        $article = Article::query()->create(['title' => 'X', 'author_id' => $author->id]);
        $article->workflow()->cancel($author, 'Story dropped');

        $this->assertFalse($article->workflow()->can('submit', $author));
        $this->assertSame('Story dropped', WorkflowHistory::query()->latest('id')->first()->comment);
    }

    public function test_starting_an_existing_subject_does_not_touch_its_updated_at(): void
    {
        $article = Article::withoutEvents(fn () => Article::query()->create(['title' => 'Old', 'updated_at' => now()->subYear()]));
        $before = $article->updated_at->timestamp;

        $this->artisan('workflow:backfill')->assertSuccessful();

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame($before, $article->updated_at->timestamp);
    }
}
