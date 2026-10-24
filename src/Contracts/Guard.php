<?php

declare(strict_types=1);

namespace Atannex\Workflow\Contracts;

use Atannex\Workflow\Engine\Context;
use Atannex\Workflow\Guards\GuardResult;

/**
 * Decides whether a transition (or a vote on it) may happen.
 */
interface Guard
{
    public function check(Context $context): GuardResult;
}
