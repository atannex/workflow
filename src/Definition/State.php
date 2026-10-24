<?php

declare(strict_types=1);

namespace Atannex\Workflow\Definition;

use Atannex\Workflow\Engine\Context;
use Closure;
use DateInterval;
use Illuminate\Support\Str;

/**
 * A place a subject can be in. Entering it may hand out tasks and start
 * timers; leaving it closes them.
 */
final class State
{
    public string $label;

    public ?string $description = null;

    public string $color = 'gray';

    public ?string $icon = null;

    public bool $final = false;

    /** @var array<string, TaskSpec> */
    public array $tasks = [];

    /** @var array<int, Timer> */
    public array $timers = [];

    /** @var array<int, Closure(Context): void> */
    public array $onEnter = [];

    /** @var array<int, Closure(Context): void> */
    public array $onLeave = [];

    /** @var array<string, mixed> */
    public array $meta = [];

    public function __construct(public readonly string $name)
    {
        $this->label = Str::headline($name);
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

    /**
     * The workflow is complete while the subject is here (it may still leave
     * again, e.g. archived → published, which reopens it).
     */
    public function final(bool $final = true): self
    {
        $this->final = $final;

        return $this;
    }

    /**
     * Hand out a task on entry: ->task('review', fn (TaskSpec $t) => $t->assign('role:editor')->due('1 day')).
     *
     * @param  (Closure(TaskSpec): mixed)|null  $configure
     */
    public function task(string $name, ?Closure $configure = null): self
    {
        $spec = $this->tasks[$name] ?? new TaskSpec($name);

        if ($configure !== null) {
            $configure($spec);
        }

        $this->tasks[$name] = $spec;

        return $this;
    }

    /**
     * Shorthand for a single task named "default".
     */
    public function assign(string|Closure ...$assignees): self
    {
        return $this->task('default', fn (TaskSpec $task) => $task->assign(...$assignees));
    }

    public function after(int|string|DateInterval $after, string $transition): self
    {
        $this->timers[] = Timer::after($after, $transition);

        return $this;
    }

    /**
     * @param  Closure(Context): mixed  $moment
     */
    public function at(Closure $moment, string $transition): self
    {
        $this->timers[] = Timer::at($moment, $transition);

        return $this;
    }

    /**
     * @param  Closure(Context): void  $callback
     */
    public function onEnter(Closure $callback): self
    {
        $this->onEnter[] = $callback;

        return $this;
    }

    /**
     * @param  Closure(Context): void  $callback
     */
    public function onLeave(Closure $callback): self
    {
        $this->onLeave[] = $callback;

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
