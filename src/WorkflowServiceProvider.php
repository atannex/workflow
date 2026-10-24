<?php

declare(strict_types=1);

namespace Atannex\Workflow;

use Atannex\Workflow\Console\BackfillCommand;
use Atannex\Workflow\Console\ListCommand;
use Atannex\Workflow\Console\ShowCommand;
use Atannex\Workflow\Console\TickCommand;
use Atannex\Workflow\Console\ValidateCommand;
use Atannex\Workflow\Engine\WorkflowEngine;
use Atannex\Workflow\Guards\GuardFactory;
use Atannex\Workflow\Support\Assignees;
use Illuminate\Support\ServiceProvider;

class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/workflow.php', 'workflow');

        $this->app->singleton(WorkflowRegistry::class, fn ($app) => new WorkflowRegistry($app, (array) $app['config']->get('workflow.workflows', [])));
        $this->app->singleton(GuardFactory::class);
        $this->app->singleton(Assignees::class);

        $this->app->singleton(WorkflowEngine::class, fn ($app) => new WorkflowEngine(
            $app->make(WorkflowRegistry::class),
            $app->make(GuardFactory::class),
            $app->make(Assignees::class),
            $app['events'],
            $app['db'],
            $app['validator'],
            (int) $app['config']->get('workflow.max_automatic_hops', 10),
            (int) $app['config']->get('workflow.tick_batch', 500),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/workflow.php' => config_path('workflow.php')], 'workflow-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'workflow-migrations');

            $this->commands([
                ListCommand::class,
                ShowCommand::class,
                ValidateCommand::class,
                TickCommand::class,
                BackfillCommand::class,
            ]);
        }
    }
}
