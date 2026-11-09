<?php

namespace Atannex\Workflow\Tests\Feature;

use Atannex\Workflow\Engine\WorkflowEngine;
use Atannex\Workflow\Models\WorkflowHistory;
use Atannex\Workflow\Models\WorkflowInstance;
use Atannex\Workflow\Models\WorkflowTask;
use Atannex\Workflow\Tests\Fixtures\Article;
use Atannex\Workflow\Tests\Fixtures\Good;
use Atannex\Workflow\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * workflow.follow_subject_connection: a subject on its own database keeps
 * its instances, tasks, history and votes there, scopes still run in one
 * query, and the scheduler covers every connection.
 */
class SubjectConnectionTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.shop', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('workflow.follow_subject_connection', true);
        $app['config']->set('workflow.connections', ['shop']);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::connection('shop')->create('goods', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('author_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->artisan('migrate', ['--database' => 'shop', '--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true]);
    }

    public function test_a_subject_on_its_own_database_keeps_its_workflow_there(): void
    {
        $author = $this->user('Ayuk');
        $editor = $this->user('Ebot', ['editor']);
        $good = Good::query()->create(['title' => 'Toghu', 'author_id' => $author->id]);
        $article = Article::query()->create(['title' => 'Budget']);

        $good->workflow()->apply('submit', $author);
        $good->workflow()->apply('approve', $editor);

        $this->assertSame('approved', $good->fresh()->status);
        $this->assertSame(1, WorkflowInstance::on('shop')->count());
        $this->assertSame(1, WorkflowInstance::query()->count(), 'Only the article is on the default connection.');
        $this->assertSame($article->getMorphClass(), WorkflowInstance::query()->value('subject_type'));
        $this->assertSame(3, WorkflowHistory::on('shop')->count());
        $this->assertSame(1, WorkflowTask::on('shop')->count());
        $this->assertSame(0, WorkflowTask::query()->count());

        $this->assertSame([$good->id], Good::query()->inWorkflowState('approved')->pluck('id')->all());
        $this->assertSame('approved', $good->fresh()->workflow()->state());
    }

    public function test_the_scheduler_covers_every_database(): void
    {
        $author = $this->user('Ayuk');
        $this->user('Ebot', ['editor']);
        $good = Good::query()->create(['title' => 'Toghu', 'author_id' => $author->id]);
        $good->workflow()->apply('submit', $author);
        WorkflowTask::on('shop')->update(['due_at' => now()->subDay()]);

        $report = app(WorkflowEngine::class)->tick();

        $this->assertSame(1, $report['overdue']);
        $this->assertNotNull(WorkflowTask::on('shop')->value('overdue_at'));
    }

    public function test_a_central_subject_keeps_its_workflow_on_the_default_connection(): void
    {
        config(['workflow.central_subjects' => [Good::class]]);
        $author = $this->user('Ayuk');
        $good = Good::query()->create(['title' => 'Toghu', 'author_id' => $author->id]);

        $good->workflow()->apply('submit', $author);

        $this->assertSame(0, WorkflowInstance::on('shop')->count());
        $this->assertSame(1, WorkflowInstance::query()->where('subject_type', $good->getMorphClass())->count());
        $this->assertSame('review', $good->fresh()->workflow()->state());
    }
}
