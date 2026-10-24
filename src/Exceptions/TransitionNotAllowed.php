<?php

declare(strict_types=1);

namespace Atannex\Workflow\Exceptions;

/**
 * The transition cannot be taken now: wrong state, a guard said no, or the
 * instance is not active. reasons() lists why, for the user.
 */
final class TransitionNotAllowed extends WorkflowException
{
    /**
     * @param  array<int, string>  $reasons
     */
    public function __construct(
        public readonly string $transition,
        private readonly array $reasons,
    ) {
        parent::__construct("[{$transition}] is not allowed: ".implode(' ', $reasons));
    }

    /**
     * @return array<int, string>
     */
    public function reasons(): array
    {
        return $this->reasons;
    }
}
