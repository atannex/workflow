<?php

declare(strict_types=1);

namespace Atannex\Workflow\Definition;

use Atannex\Workflow\Exceptions\InvalidDefinition;
use Atannex\Workflow\Exceptions\UnknownTransition;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A workflow: its states, the transitions between them, which models it
 * runs on, and how it maps onto those models.
 *
 *     WorkflowDefinition::make('editorial')
 *         ->for(Post::class)
 *         ->column('flag')
 *         ->initial('draft')
 *         ->state('draft')
 *         ->state('review', fn (State $s) => $s->assign('role:editor')->after('3 days', 'remind'))
 *         ->transition('submit', fn (Transition $t) => $t->from('draft')->to('review')->guard('owner'));
 */
final class WorkflowDefinition
{
    public string $label;

    public ?string $description = null;

    /** @var array<int, class-string<Model>> */
    public array $subjects = [];

    public ?string $initial = null;

    /** Subject attribute kept equal to the current state. */
    public ?string $column = null;

    public bool $autoStart = true;

    /** @var array<string, State> */
    public array $states = [];

    /** @var array<string, Transition> */
    public array $transitions = [];

    /** @var (Closure(Model): ?string)|null */
    public ?Closure $inferUsing = null;

    /** @var (Closure(Model, string): ?string)|null */
    public ?Closure $reconcileUsing = null;

    /** @var (Closure(Model): mixed)|null */
    public ?Closure $ownerUsing = null;

    /** @var array<string, mixed> */
    public array $meta = [];

    public function __construct(public readonly string $name)
    {
        $this->label = Str::headline($name);
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @param  class-string<Model>  ...$models
     */
    public function for(string ...$models): self
    {
        $this->subjects = array_values(array_unique([...$this->subjects, ...$models]));

        return $this;
    }

    public function initial(string $state): self
    {
        $this->initial = $state;

        return $this;
    }

    public function column(?string $column): self
    {
        $this->column = $column;

        return $this;
    }

    /**
     * Start an instance as soon as a subject is created (default), or only
     * when start() is called.
     */
    public function autoStart(bool $autoStart = true): self
    {
        $this->autoStart = $autoStart;

        return $this;
    }

    /**
     * @param  (Closure(State): mixed)|null  $configure
     */
    public function state(string $name, ?Closure $configure = null): self
    {
        $state = $this->states[$name] ?? new State($name);

        if ($configure !== null) {
            $configure($state);
        }

        $this->states[$name] = $state;
        $this->initial ??= $name;

        return $this;
    }

    /**
     * @param  (Closure(Transition): mixed)|null  $configure
     */
    public function transition(string $name, ?Closure $configure = null): self
    {
        $transition = $this->transitions[$name] ?? new Transition($name);

        if ($configure !== null) {
            $configure($transition);
        }

        $this->transitions[$name] = $transition;

        return $this;
    }

    /**
     * The state an existing subject is in when its instance starts (e.g. a
     * post with a past publication date is "published"). Null = initial.
     *
     * @param  Closure(Model): ?string  $infer
     */
    public function inferUsing(Closure $infer): self
    {
        $this->inferUsing = $infer;

        return $this;
    }

    /**
     * After the subject is saved outside the engine, the state it should now
     * be in (or null to leave it). Without this, a changed mirror column is used.
     *
     * @param  Closure(Model, string): ?string  $reconcile  (subject, current state)
     */
    public function reconcileUsing(Closure $reconcile): self
    {
        $this->reconcileUsing = $reconcile;

        return $this;
    }

    /**
     * Who owns a subject (a user, user id or null), for the "owner" and
     * "not-owner" guards and "owner" task assignees.
     *
     * @param  Closure(Model): mixed  $owner
     */
    public function ownerUsing(Closure $owner): self
    {
        $this->ownerUsing = $owner;

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

    public function appliesTo(Model|string $subject): bool
    {
        $class = $subject instanceof Model ? $subject::class : $subject;

        foreach ($this->subjects as $model) {
            if ($class === $model || is_subclass_of($class, $model)) {
                return true;
            }
        }

        return false;
    }

    public function hasState(?string $state): bool
    {
        return $state !== null && isset($this->states[$state]);
    }

    public function getState(string $state): State
    {
        return $this->states[$state] ?? throw new InvalidDefinition("Workflow [{$this->name}] has no state [{$state}].");
    }

    public function getTransition(string $transition): Transition
    {
        return $this->transitions[$transition] ?? throw UnknownTransition::make($this->name, $transition);
    }

    public function isFinal(string $state): bool
    {
        return $this->states[$state]->final ?? false;
    }

    /**
     * Transitions that leave a state ("*" covers every non-final state).
     *
     * @return array<string, Transition>
     */
    public function transitionsFrom(string $state): array
    {
        return array_filter($this->transitions, fn (Transition $transition): bool => in_array($state, $transition->from, true)
            || (in_array('*', $transition->from, true) && ! $this->isFinal($state) && $transition->to !== $state));
    }

    public function owner(Model $subject): mixed
    {
        return $this->ownerUsing !== null ? ($this->ownerUsing)($subject) : null;
    }

    /**
     * Problems that make the definition unusable (errors) or suspicious
     * (warnings).
     *
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function problems(): array
    {
        $errors = [];
        $warnings = [];

        if ($this->states === []) {
            $errors[] = 'It has no states.';
        }

        if (! $this->hasState($this->initial)) {
            $errors[] = "The initial state [{$this->initial}] does not exist.";
        }

        if ($this->subjects === []) {
            $warnings[] = 'It is not attached to any model (for()).';
        }

        foreach ($this->transitions as $name => $transition) {
            if ($transition->to === null) {
                $errors[] = "Transition [{$name}] has no target state.";
            } elseif (! $this->hasState($transition->to)) {
                $errors[] = "Transition [{$name}] goes to an unknown state [{$transition->to}].";
            }

            if ($transition->from === []) {
                $errors[] = "Transition [{$name}] has no source state.";
            }

            foreach ($transition->from as $from) {
                if ($from !== '*' && ! $this->hasState($from)) {
                    $errors[] = "Transition [{$name}] starts from an unknown state [{$from}].";
                }
            }

            $reject = $transition->approval?->rejectTransition;

            if ($reject !== null) {
                if (! isset($this->transitions[$reject])) {
                    $errors[] = "Transition [{$name}] rejects with an unknown transition [{$reject}].";
                } else {
                    foreach ($transition->from as $from) {
                        if ($from !== '*' && ! $this->transitions[$reject]->leavesFrom($from)) {
                            $errors[] = "Rejecting [{$name}] uses [{$reject}], which cannot leave [{$from}].";
                        }
                    }
                }
            }

            if ($transition->automatic && $transition->approval !== null) {
                $errors[] = "Transition [{$name}] cannot be both automatic and need approvals.";
            }
        }

        foreach ($this->states as $stateName => $state) {
            foreach ($state->timers as $timer) {
                $this->checkEngineTransition($stateName, $timer->transition, 'timer', $errors);
            }

            foreach ($state->tasks as $task) {
                if ($task->assignees === []) {
                    $errors[] = "Task [{$task->name}] in [{$stateName}] has nobody assigned.";
                }

                if ($task->overdueTransition !== null) {
                    $this->checkEngineTransition($stateName, $task->overdueTransition, "task [{$task->name}] deadline", $errors);
                }

                if (($task->escalateTo !== [] || $task->overdueTransition !== null) && $task->due === null) {
                    $warnings[] = "Task [{$task->name}] in [{$stateName}] escalates but has no deadline (due()).";
                }
            }

            if (! $state->final && $this->transitionsFrom($stateName) === []) {
                $warnings[] = "State [{$stateName}] is a dead end: no transition leaves it and it is not final.";
            }
        }

        foreach (array_diff(array_keys($this->states), $this->reachable()) as $state) {
            $warnings[] = "State [{$state}] cannot be reached from [{$this->initial}].";
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * @throws InvalidDefinition
     */
    public function assertValid(): self
    {
        $errors = $this->problems()['errors'];

        if ($errors !== []) {
            throw new InvalidDefinition("Workflow [{$this->name}] is invalid:\n- ".implode("\n- ", $errors));
        }

        return $this;
    }

    /**
     * States reachable from the initial state (inferred states count too:
     * every state that is the target of some transition or the initial one).
     *
     * @return array<int, string>
     */
    public function reachable(): array
    {
        if (! $this->hasState($this->initial)) {
            return [];
        }

        $seen = [$this->initial => true];
        $queue = [$this->initial];

        while ($queue !== []) {
            $state = array_shift($queue);

            foreach ($this->transitionsFrom($state) as $transition) {
                if ($transition->to !== null && ! isset($seen[$transition->to])) {
                    $seen[$transition->to] = true;
                    $queue[] = $transition->to;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function checkEngineTransition(string $state, string $transition, string $what, array &$errors): void
    {
        if (! isset($this->transitions[$transition])) {
            $errors[] = "The {$what} in [{$state}] uses an unknown transition [{$transition}].";
        } elseif (! isset($this->transitionsFrom($state)[$transition])) {
            $errors[] = "The {$what} in [{$state}] uses [{$transition}], which cannot leave [{$state}].";
        }
    }
}
