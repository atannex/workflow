<?php

declare(strict_types=1);

namespace Atannex\Workflow\Exceptions;

/**
 * Someone else moved the subject on since the caller looked at it.
 */
final class StaleState extends WorkflowException
{
    public function __construct(public readonly string $expected, public readonly string $actual)
    {
        parent::__construct("Expected the state [{$expected}], but it is now [{$actual}]. Reload and try again.");
    }
}
