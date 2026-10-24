<?php

declare(strict_types=1);

namespace Atannex\Workflow\Guards;

use Atannex\Workflow\Contracts\Guard;
use Atannex\Workflow\Engine\Context;
use Atannex\Workflow\Support\Assignees;

/**
 * "role:editor,admin": the actor holds one of the roles.
 */
final class HasRole implements Guard
{
    /**
     * @param  array<int, string>  $roles
     */
    public function __construct(private readonly array $roles) {}

    public function check(Context $context): GuardResult
    {
        if ($context->isSystem()) {
            return GuardResult::allow();
        }

        $user = $context->user();
        $held = $user !== null ? app(Assignees::class)->identities($user)['role'] : [];

        return array_intersect($this->roles, $held) !== []
            ? GuardResult::allow()
            : GuardResult::deny(__('Only :roles can do this.', ['roles' => implode(', ', $this->roles)]));
    }
}
