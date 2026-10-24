<?php

declare(strict_types=1);

namespace Atannex\Workflow\Events;

use Atannex\Workflow\Engine\Context;
use Atannex\Workflow\Models\WorkflowHistory;

/** A transition happened (dispatched after the transaction commits). */
final class Transitioned
{
    public function __construct(
        public readonly Context $context,
        public readonly WorkflowHistory $entry,
    ) {}
}
