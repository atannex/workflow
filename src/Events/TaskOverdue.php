<?php

declare(strict_types=1);

namespace Atannex\Workflow\Events;

use Atannex\Workflow\Models\WorkflowInstance;
use Atannex\Workflow\Models\WorkflowTask;

/** A task passed its deadline (once per task). */
final class TaskOverdue
{
    public function __construct(
        public readonly WorkflowTask $task,
        public readonly WorkflowInstance $instance,
    ) {}
}
