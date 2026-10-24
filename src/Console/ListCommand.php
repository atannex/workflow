<?php

declare(strict_types=1);

namespace Atannex\Workflow\Console;

use Atannex\Workflow\Models\WorkflowInstance;
use Atannex\Workflow\WorkflowRegistry;
use Illuminate\Console\Command;

final class ListCommand extends Command
{
    protected $signature = 'workflow:list';

    protected $description = 'List registered workflows with their models and how many instances sit in each state';

    public function handle(WorkflowRegistry $registry): int
    {
        $definitions = $registry->all();

        if ($definitions === []) {
            $this->components->warn('No workflows are registered (config/workflow.php → workflows).');

            return self::SUCCESS;
        }

        foreach ($definitions as $definition) {
            $counts = WorkflowInstance::query()
                ->where('workflow', $definition->name)
                ->selectRaw('state, count(*) as total')
                ->groupBy('state')
                ->pluck('total', 'state');

            $this->components->twoColumnDetail("<fg=green;options=bold>{$definition->name}</> {$definition->label}", implode(', ', array_map('class_basename', $definition->subjects)));

            foreach ($definition->states as $state) {
                $this->components->twoColumnDetail('  '.$state->name.($state->final ? ' <fg=gray>(final)</>' : ''), (string) ($counts[$state->name] ?? 0));
            }
        }

        return self::SUCCESS;
    }
}
