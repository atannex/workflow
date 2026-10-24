<?php

declare(strict_types=1);

namespace Atannex\Workflow\Events;

use Atannex\Workflow\Models\WorkflowInstance;

/** The instance was cancelled. */
final class WorkflowCancelled
{
    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly ?string $reason,
    ) {}
}
