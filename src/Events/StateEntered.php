<?php

declare(strict_types=1);

namespace Atannex\Workflow\Events;

use Atannex\Workflow\Models\WorkflowInstance;
use Atannex\Workflow\Support\Actor;
use Illuminate\Database\Eloquent\Model;

/** The subject entered a state (by a transition, start or sync). */
final class StateEntered
{
    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly Model $subject,
        public readonly string $state,
        public readonly ?string $from,
        public readonly Actor $actor,
    ) {}
}
