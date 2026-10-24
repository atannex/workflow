<?php

declare(strict_types=1);

namespace Atannex\Workflow\Events;

use Atannex\Workflow\Models\WorkflowTask;
use Illuminate\Contracts\Auth\Authenticatable;

/** Someone completed a task. */
final class TaskCompleted
{
    public function __construct(
        public readonly WorkflowTask $task,
        public readonly ?Authenticatable $user,
    ) {}
}
