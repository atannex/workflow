<?php

declare(strict_types=1);

namespace Atannex\Workflow\Definition;

use Atannex\Workflow\Contracts\Guard;
use Closure;

/**
 * A transition that needs several people to agree. Each eligible user votes
 * once per visit to the state (votes are tied to the instance's round); the
 * transition happens when enough approvals are in, and a rejection (or
 * enough of them) takes the reject transition instead.
 */
final class ApprovalRule
{
    /** @var array<int, string|Guard|Closure> */
    public array $voters = [];

    public ?string $rejectTransition = null;

    public int $rejections = 1;

    public bool $commentOnReject = true;

    public function __construct(public int $required = 1) {}

    /**
     * Who may vote (guards). Defaults to the transition's own guards.
     */
    public function voters(string|Guard|Closure ...$guards): self
    {
        $this->voters = [...$this->voters, ...$guards];

        return $this;
    }

    /**
     * Transition taken when the rejection threshold is reached.
     */
    public function rejectWith(string $transition, int $rejections = 1): self
    {
        $this->rejectTransition = $transition;
        $this->rejections = max(1, $rejections);

        return $this;
    }

    public function commentOnReject(bool $required = true): self
    {
        $this->commentOnReject = $required;

        return $this;
    }
}
