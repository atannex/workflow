<?php

declare(strict_types=1);

namespace Atannex\Workflow\Guards;

use Atannex\Workflow\Contracts\Guard;
use Atannex\Workflow\Engine\Context;
use Illuminate\Support\Facades\Gate;

/**
 * "can:posts.publish" or "can:publish": the actor passes the gate (policy
 * ability or permission) for the subject. The system always may.
 */
final class Can implements Guard
{
    public function __construct(private readonly string $ability) {}

    public function check(Context $context): GuardResult
    {
        if ($context->isSystem()) {
            return GuardResult::allow();
        }

        $user = $context->user();

        return $user !== null && Gate::forUser($user)->allows($this->ability, $context->subject)
            ? GuardResult::allow()
            : GuardResult::deny(__('You do not have permission to do this.'));
    }
}
