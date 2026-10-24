<?php

declare(strict_types=1);

namespace Atannex\Workflow\Console;

use Atannex\Workflow\Engine\WorkflowEngine;
use Atannex\Workflow\Models\WorkflowInstance;
use Atannex\Workflow\WorkflowRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class BackfillCommand extends Command
{
    protected $signature = 'workflow:backfill
        {workflow? : Only this workflow}
        {--chunk=500 : Records per batch}
        {--dry-run : Count what would be started without starting anything}';

    protected $description = 'Start workflow instances for records that existed before the workflow (their state is inferred)';

    public function handle(WorkflowRegistry $registry, WorkflowEngine $engine): int
    {
        $definitions = $this->argument('workflow') !== null
            ? [$registry->get((string) $this->argument('workflow'))]
            : $registry->all();

        foreach ($definitions as $definition) {
            foreach ($definition->subjects as $class) {
                /** @var Model $model */
                $model = new $class;
                $query = $class::query()->withoutGlobalScopes();

                if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                    $query->withTrashed();
                }

                $query->whereNotExists(fn ($q) => $q->selectRaw('1')
                    ->from((new WorkflowInstance)->getTable())
                    ->where('workflow', $definition->name)
                    ->where('subject_type', $model->getMorphClass())
                    ->whereRaw('subject_id = '.$model->getConnection()->getQueryGrammar()->wrap($model->getQualifiedKeyName())));

                $missing = (clone $query)->count();
                $label = "{$definition->name} · ".class_basename($class);

                if ($this->option('dry-run') || $missing === 0) {
                    $this->components->twoColumnDetail($label, "{$missing} to start");

                    continue;
                }

                $started = 0;

                $this->components->task("{$label}: {$missing}", function () use ($query, $engine, $definition, &$started) {
                    $query->chunkById((int) $this->option('chunk'), function ($records) use ($engine, $definition, &$started) {
                        foreach ($records as $record) {
                            $engine->start($record, $definition->name);

                            if (method_exists($record, 'trashed') && $record->trashed()) {
                                $engine->suspend($record);
                            }

                            $started++;
                        }
                    });
                });

                $this->components->twoColumnDetail($label, "{$started} started");
            }
        }

        return self::SUCCESS;
    }
}
