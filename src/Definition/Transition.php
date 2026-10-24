<?php

declare(strict_types=1);

namespace Atannex\Workflow\Definition;

use Atannex\Workflow\Contracts\Guard;
use Atannex\Workflow\Engine\Context;
use Closure;
use Illuminate\Support\Str;

/**
 * A move from one or more states to another, with who may take it (guards),
 * what it needs (comment, input), how many people must agree (approvals)
 * and what it does (effects).
 */
final class Transition
{
    /** @var array<int, string> */
    public array $from = [];

    public ?string $to = null;

    public string $label;

    public string $color = 'primary';

    public ?string $icon = null;

    public ?string $description = null;

    public ?string $confirmation = null;

    /** @var array<int, string|Guard|Closure> */
    public array $guards = [];

    public ?ApprovalRule $approval = null;

    public bool $requiresComment = false;

    /** @var array<string, mixed> */
    public array $rules = [];

    /** @var array<int, Closure(Context): void> */
    public array $before = [];

    /** @var array<int, Closure(Context): void> */
    public array $after = [];

    /** Tried by the engine itself after every change and on every tick. */
    public bool $automatic = false;

    /** Only the engine (timers, deadlines, automatic) may take it. */
    public bool $system = false;

    /** Not offered in user interfaces (still callable from code). */
    public bool $hidden = false;

    /** @var array<string, mixed> */
    public array $meta = [];

    public function __construct(public readonly string $name)
    {
        $this->label = Str::headline($name);
    }

    /**
     * Source states; "*" means any non-final state.
     */
    public function from(string ...$states): self
    {
        $this->from = array_values(array_unique([...$this->from, ...$states]));

        return $this;
    }

    public function to(string $state): self
    {
        $this->to = $state;

        return $this;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function color(string $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Ask the user to confirm (message shown in the UI).
     */
    public function confirm(string $message): self
    {
        $this->confirmation = $message;

        return $this;
    }

    /**
     * Guards that must all pass: "can:posts.publish", "role:editor,admin",
     * "owner", "not-owner", "not-actor:submit", "filled:title,body",
     * "tasks-complete", a Guard, or a closure (Context) returning bool or a
     * denial reason.
     */
    public function guard(string|Guard|Closure ...$guards): self
    {
        $this->guards = [...$this->guards, ...$guards];

        return $this;
    }

    /**
     * Needs $required approvals from different people.
     *
     * @param  (Closure(ApprovalRule): mixed)|null  $configure
     */
    public function approvals(int $required = 1, ?Closure $configure = null): self
    {
        $this->approval = new ApprovalRule(max(1, $required));

        if ($configure !== null) {
            $configure($this->approval);
        }

        return $this;
    }

    public function requiresComment(bool $required = true): self
    {
        $this->requiresComment = $required;

        return $this;
    }

    /**
     * Validation rules for the input given with the transition
     * (e.g. ['publish_at' => ['required', 'date', 'after:now']]).
     *
     * @param  array<string, mixed>  $rules
     */
    public function input(array $rules): self
    {
        $this->rules = [...$this->rules, ...$rules];

        return $this;
    }

    /**
     * Runs before the state changes, inside the transaction; throwing aborts
     * the transition and rolls everything back. Changes to the subject are saved.
     *
     * @param  Closure(Context): void  $effect
     */
    public function before(Closure $effect): self
    {
        $this->before[] = $effect;

        return $this;
    }

    /**
     * Runs after the state changed, inside the same transaction.
     *
     * @param  Closure(Context): void  $effect
     */
    public function after(Closure $effect): self
    {
        $this->after[] = $effect;

        return $this;
    }

    public function automatic(bool $automatic = true): self
    {
        $this->automatic = $automatic;

        return $this;
    }

    public function system(bool $system = true): self
    {
        $this->system = $system;

        return $this;
    }

    public function hidden(bool $hidden = true): self
    {
        $this->hidden = $hidden;

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

    public function leavesFrom(string $state): bool
    {
        return in_array('*', $this->from, true) || in_array($state, $this->from, true);
    }

    public function offeredToUsers(): bool
    {
        return ! $this->system && ! $this->automatic && ! $this->hidden;
    }
}
