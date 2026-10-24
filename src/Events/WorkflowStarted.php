<?php

declare(strict_types=1);

namespace Atannex\Workflow\Events;

use Atannex\Workflow\Models\WorkflowInstance;
use Atannex\Workflow\Support\Actor;
use Illuminate\Database\Eloquent\Model;

/** An instance started (in its initial or inferred state). */
final class WorkflowStarted
{
    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly Model $subject,
        public readonly Actor $actor,
    ) {}
}
