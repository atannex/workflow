<?php

declare(strict_types=1);

namespace Atannex\Workflow\Guards;

use Atannex\Workflow\Contracts\Guard;
use Atannex\Workflow\Engine\Context;

/**
 * "owner" / "not-owner": the actor is (or is not) the subject's owner, as
 * resolved by the definition's ownerUsing(). "not-owner" is separation of
 * duties: nobody reviews or approves their own work.
 */
final class Owner implements Guard
{
    public function __construct(private readonly bool $mustOwn = true) {}

    public function check(Context $context): GuardResult
    {
        if ($context->isSystem()) {
            return GuardResult::allow();
        }

        $owns = $context->actorIsOwner();

        return match (true) {
            $this->mustOwn && ! $owns => GuardResult::deny(__('Only the owner can do this.')),
            ! $this->mustOwn && $owns => GuardResult::deny(__('You cannot do this on your own work.')),
            default => GuardResult::allow(),
        };
    }
}
