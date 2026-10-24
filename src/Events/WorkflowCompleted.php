<?php

declare(strict_types=1);

namespace Atannex\Workflow\Events;

use Atannex\Workflow\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;

/** The instance reached a final state. */
final class WorkflowCompleted
{
    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly Model $subject,
    ) {}
}
