<?php

declare(strict_types=1);

namespace Atannex\Workflow\Guards;

use Atannex\Workflow\Contracts\Guard;
use Atannex\Workflow\Engine\Context;
use Closure;

/**
 * Passes when any one of the guards passes: new AnyOf('owner', 'can:posts.update').
 */
final class AnyOf implements Guard
{
    /** @var array<int, string|Guard|Closure> */
    private array $guards;

    public function __construct(string|Guard|Closure ...$guards)
    {
        $this->guards = $guards;
    }

    public function check(Context $context): GuardResult
    {
        $factory = app(GuardFactory::class);
        $denied = GuardResult::allow();

        foreach ($this->guards as $guard) {
            $result = $factory->make($guard)->check($context);

            if ($result->allowed) {
                return $result;
            }

            $denied = $denied->merge($result);
        }

        return $denied;
    }
}
