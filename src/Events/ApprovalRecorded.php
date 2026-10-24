<?php

declare(strict_types=1);

namespace Atannex\Workflow\Events;

use Atannex\Workflow\Models\WorkflowApproval;
use Atannex\Workflow\Models\WorkflowInstance;

/** Someone voted on a transition that needs approvals. */
final class ApprovalRecorded
{
    public function __construct(
        public readonly WorkflowApproval $approval,
        public readonly WorkflowInstance $instance,
        public readonly int $approvals,
        public readonly int $required,
    ) {}
}
