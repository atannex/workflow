<?php

declare(strict_types=1);

namespace Atannex\Workflow\Exceptions;

final class UnknownTransition extends WorkflowException
{
    public static function make(string $workflow, string $transition): self
    {
        return new self("Workflow [{$workflow}] has no transition [{$transition}].");
    }
}
