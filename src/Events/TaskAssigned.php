<?php

declare(strict_types=1);

namespace Atannex\Workflow\Events;

use Atannex\Workflow\Models\WorkflowInstance;
use Atannex\Workflow\Models\WorkflowTask;

/** A task was handed out (on entering a state, by escalation or reassignment). */
final class TaskAssigned
{
    public function __construct(
        public readonly WorkflowTask $task,
        public readonly WorkflowInstance $instance,
        public readonly bool $escalated = false,
    ) {}
}
