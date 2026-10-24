<?php

namespace Atannex\Workflow\Tests\Feature;

use Atannex\Workflow\Models\WorkflowInstance;
use Atannex\Workflow\Tests\Fixtures\Article;
use Atannex\Workflow\Tests\TestCase;

class ConsoleTest extends TestCase
{
    public function test_commands(): void
    {
        $this->artisan('workflow:list')->expectsOutputToContain('editorial')->assertSuccessful();
        $this->artisan('workflow:show', ['workflow' => 'editorial'])->assertSuccessful();
        $this->artisan('workflow:show', ['workflow' => 'editorial', '--mermaid' => true])->expectsOutputToContain('stateDiagram-v2')->assertSuccessful();
        $this->artisan('workflow:validate')->assertSuccessful();
    }

    public function test_backfill_starts_missing_instances_with_inferred_states(): void
    {
        Article::withoutEvents(function () {
            Article::query()->create(['title' => 'Draft']);
            Article::query()->create(['title' => 'Live', 'published_at' => now()->subDay()]);
            Article::query()->create(['title' => 'Trashed', 'deleted_at' => now()]);
        });

        $this->artisan('workflow:backfill', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, WorkflowInstance::query()->count());

        $this->artisan('workflow:backfill')->assertSuccessful();
        $this->artisan('workflow:backfill')->assertSuccessful();

        $this->assertSame(3, WorkflowInstance::query()->count());
        $this->assertSame(['draft', 'published', 'draft'], WorkflowInstance::query()->orderBy('id')->pluck('state')->all());
        $this->assertSame(WorkflowInstance::SUSPENDED, WorkflowInstance::query()->orderByDesc('id')->value('status'));
    }
}
