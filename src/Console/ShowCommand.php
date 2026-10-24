<?php

declare(strict_types=1);

namespace Atannex\Workflow\Console;

use Atannex\Workflow\Support\Diagram;
use Atannex\Workflow\Support\Duration;
use Atannex\Workflow\WorkflowRegistry;
use Illuminate\Console\Command;

final class ShowCommand extends Command
{
    protected $signature = 'workflow:show {workflow : The workflow name} {--mermaid : Print a Mermaid state diagram instead}';

    protected $description = 'Show the states, transitions, tasks and timers of a workflow';

    public function handle(WorkflowRegistry $registry): int
    {
        $definition = $registry->get((string) $this->argument('workflow'));

        if ($this->option('mermaid')) {
            $this->line(Diagram::mermaid($definition));

            return self::SUCCESS;
        }

        $this->components->info("{$definition->label} ({$definition->name})");

        $this->table(['State', 'Label', 'Tasks', 'Timers', 'Final'], array_map(fn ($state) => [
            $state->name.($state->name === $definition->initial ? ' *' : ''),
            $state->label,
            implode("\n", array_map(fn ($task) => $task->name.' → '.implode(', ', array_map(fn ($a) => is_string($a) ? $a : 'closure', $task->assignees))
                .($task->due !== null ? ' (due '.Duration::human(Duration::seconds($task->due)).')' : ''), $state->tasks)),
            count($state->timers),
            $state->final ? 'yes' : '',
        ], array_values($definition->states)));

        $this->table(['Transition', 'From', 'To', 'Guards', 'Notes'], array_map(fn ($transition) => [
            $transition->name,
            implode(', ', $transition->from),
            $transition->to,
            implode("\n", array_map(fn ($guard) => is_string($guard) ? $guard : (is_object($guard) && ! $guard instanceof \Closure ? class_basename($guard) : 'closure'), $transition->guards)),
            implode(', ', array_filter([
                $transition->approval ? "{$transition->approval->required} approval(s)" : null,
                $transition->approval?->rejectTransition ? "reject → {$transition->approval->rejectTransition}" : null,
                $transition->requiresComment ? 'comment' : null,
                $transition->rules !== [] ? 'input: '.implode(', ', array_keys($transition->rules)) : null,
                $transition->automatic ? 'automatic' : null,
                $transition->system ? 'system' : null,
            ])),
        ], array_values($definition->transitions)));

        return self::SUCCESS;
    }
}
