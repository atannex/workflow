<?php

declare(strict_types=1);

namespace Atannex\Workflow\Guards;

use Atannex\Workflow\Contracts\Guard;
use Atannex\Workflow\Engine\Context;
use Atannex\Workflow\Models\WorkflowHistory;

/**
 * "not-actor:submit,claim": the actor did not take the latest of these
 * transitions on this instance (four-eyes principle).
 */
final class NotPreviousActor implements Guard
{
    /**
     * @param  array<int, string>  $transitions
     */
    public function __construct(private readonly array $transitions) {}

    public function check(Context $context): GuardResult
    {
        if ($context->isSystem() || ! $context->instance->exists) {
            return GuardResult::allow();
        }

        $last = WorkflowHistory::query()
            ->where('instance_id', $context->instance->getKey())
            ->whereIn('transition', $this->transitions)
            ->latest('id')
            ->value('actor_id');

        return $last !== null && $context->actor->is($last)
            ? GuardResult::deny(__('Someone other than the person who did the previous step must do this.'))
            : GuardResult::allow();
    }
}
