<?php

declare(strict_types=1);

namespace Atannex\Workflow\Guards;

use Atannex\Workflow\Contracts\Guard;
use Atannex\Workflow\Engine\Context;
use Atannex\Workflow\Models\WorkflowTask;

/**
 * "tasks-complete" or "tasks-complete:legal,copy": every (named) task handed
 * out in the current visit to the state is done. Pair with an automatic
 * transition to join parallel work.
 */
final class TasksComplete implements Guard
{
    /**
     * @param  array<int, string>  $names
     */
    public function __construct(private readonly array $names = []) {}

    public function check(Context $context): GuardResult
    {
        $tasks = WorkflowTask::query()
            ->where('instance_id', $context->instance->getKey())
            ->where('state', $context->instance->state)
            ->where('round', $context->instance->round)
            ->where('status', '!=', WorkflowTask::CANCELLED)
            ->when($this->names !== [], fn ($query) => $query->whereIn('name', $this->names))
            ->get(['name', 'status']);

        // A task counts as done when any of its copies (escalations) is done.
        $open = $tasks->groupBy('name')
            ->reject(fn ($copies) => $copies->contains('status', WorkflowTask::COMPLETED))
            ->keys();

        $missing = array_diff($this->names, $tasks->pluck('name')->all());

        if ($tasks->isEmpty() && $this->names === []) {
            return GuardResult::allow();
        }

        return $open->isEmpty() && $missing === []
            ? GuardResult::allow()
            : GuardResult::deny(__('Waiting for: :tasks.', ['tasks' => $open->merge($missing)->unique()->implode(', ')]));
    }
}
