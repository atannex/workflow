<?php

namespace Atannex\Workflow\Tests;

use Atannex\Workflow\Support\Assignees;
use Atannex\Workflow\Tests\Fixtures\EditorialWorkflow;
use Atannex\Workflow\Tests\Fixtures\User;
use Atannex\Workflow\WorkflowServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('workflow.user_model', User::class);
        $app['config']->set('workflow.workflows', [EditorialWorkflow::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(Assignees::class)->resolveIdentitiesUsing(fn (User $user) => [
            'role' => $user->roles ?? [],
            'permission' => $user->permissions ?? [],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('roles')->nullable();
            $table->json('permissions')->nullable();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('author_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function user(string $name, array $roles = []): User
    {
        return User::query()->create(['name' => $name, 'roles' => $roles]);
    }
}
