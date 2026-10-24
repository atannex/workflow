<?php

declare(strict_types=1);

namespace Atannex\Workflow\Definition;

use Closure;
use DateInterval;

/**
 * Work handed out when a state is entered: who must act, by when, and what
 * happens when the deadline passes (escalate to someone else, and/or take a
 * transition automatically).
 */
final class TaskSpec
{
    /** @var array<int, string|Closure> */
    public array $assignees = [];

    public ?string $label = null;

    public int|string|DateInterval|null $due = null;

    /** @var array<int, string|Closure> */
    public array $escalateTo = [];

    public ?string $overdueTransition = null;

    /** @var array<string, mixed> */
    public array $meta = [];

    public function __construct(public readonly string $name) {}

    /**
     * "role:editor", "permission:posts.review", "user:12", "owner", "actor"
     * (who caused the state change), or a closure (Context $context) that
     * returns any of those, a user model or a list of them.
     */
    public function assign(string|Closure ...$assignees): self
    {
        $this->assignees = [...$this->assignees, ...$assignees];

        return $this;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function due(int|string|DateInterval $within): self
    {
        $this->due = $within;

        return $this;
    }

    /**
     * When the task is overdue, hand it also to these assignees.
     */
    public function escalateTo(string|Closure ...$assignees): self
    {
        $this->escalateTo = [...$this->escalateTo, ...$assignees];

        return $this;
    }

    /**
     * When the task is overdue, take this transition (as the system).
     */
    public function whenOverdue(string $transition): self
    {
        $this->overdueTransition = $transition;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): self
    {
        $this->meta = [...$this->meta, ...$meta];

        return $this;
    }
}
