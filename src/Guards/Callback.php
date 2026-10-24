<?php

declare(strict_types=1);

namespace Atannex\Workflow\Guards;

use Atannex\Workflow\Contracts\Guard;
use Atannex\Workflow\Engine\Context;
use Closure;

/**
 * A closure (Context) returning true/null (allow), false (deny), a denial
 * reason, or a GuardResult. Checked for the system too.
 */
final class Callback implements Guard
{
    public function __construct(private readonly Closure $callback) {}

    public function check(Context $context): GuardResult
    {
        return GuardResult::from(($this->callback)($context));
    }
}
