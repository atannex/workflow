<?php

declare(strict_types=1);

namespace Atannex\Workflow\Exceptions;

final class UnknownWorkflow extends WorkflowException
{
    public static function make(string $name): self
    {
        return new self("No workflow named [{$name}] is registered.");
    }

    public static function forSubject(string $class): self
    {
        return new self("No workflow is registered for [{$class}].");
    }

    public static function ambiguous(string $class): self
    {
        return new self("Several workflows run on [{$class}]; name the one you mean.");
    }
}
