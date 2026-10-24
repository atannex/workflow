<?php

declare(strict_types=1);

namespace Atannex\Workflow\Support;

use Atannex\Workflow\Definition\State;
use Atannex\Workflow\Definition\Transition;
use Atannex\Workflow\Definition\WorkflowDefinition;
use Atannex\Workflow\Engine\Outcome;
use Atannex\Workflow\Engine\WorkflowEngine;
use Atannex\Workflow\Exceptions\WorkflowException;
use Atannex\Workflow\Guards\GuardResult;
use Atannex\Workflow\Models\WorkflowHistory;
use Atannex\Workflow\Models\WorkflowInstance;
use Atannex\Workflow\Models\WorkflowTask;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One model in one workflow: $post->workflow()->apply('publish', $user).
 */
final class SubjectWorkflow
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly Model $subject,
        private readonly ?string $name = null,
    ) {}

    public function definition(): WorkflowDefinition
    {
        return $this->engine->definition($this->subject, $this->name);
    }

    public function instance(): ?WorkflowInstance
    {
        return $this->engine->instanceFor($this->subject, $this->name);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function start(?Authenticatable $actor = null, ?string $state = null, array $context = []): WorkflowInstance
    {
        return $this->engine->start($this->subject, $this->name, $actor, $state, $context);
    }

    public function state(): ?string
    {
        return $this->instance()?->state;
    }

    public function currentState(): ?State
    {
        $state = $this->state();

        return $state === null ? null : $this->definition()->getState($state);
    }

    public function is(string ...$states): bool
    {
        return in_array($this->state(), $states, true);
    }

    /**
     * @param  array{comment?: ?string, input?: array<string, mixed>, expect?: ?string}  $options
     */
    public function apply(string $transition, Authenticatable|Actor|null $actor = null, array $options = []): Outcome
    {
        return $this->engine->apply($this->instanceOrFail(), $transition, $actor, $options);
    }

    /**
     * @param  array{comment?: ?string, input?: array<string, mixed>, expect?: ?string}  $options
     */
    public function approve(string $transition, Authenticatable $user, array $options = []): Outcome
    {
        return $this->engine->approve($this->instanceOrFail(), $transition, $user, $options);
    }

    /**
     * @param  array{comment?: ?string, expect?: ?string}  $options
     */
    public function reject(string $transition, Authenticatable $user, array $options = []): Outcome
    {
        return $this->engine->reject($this->instanceOrFail(), $transition, $user, $options);
    }

    public function check(string $transition, Authenticatable|Actor|null $actor = null): GuardResult
    {
        return $this->engine->check($this->subject, $transition, $actor);
    }

    public function can(string $transition, Authenticatable|Actor|null $actor = null): bool
    {
        return $this->check($transition, $actor)->allowed;
    }

    /**
     * @return array<string, Transition>
     */
    public function available(Authenticatable|Actor|null $actor = null): array
    {
        return $this->engine->available($this->subject, $actor);
    }

    /**
     * @return Collection<int, WorkflowHistory>
     */
    public function history(): Collection
    {
        return $this->instance()?->history()->with('actor')->get() ?? collect();
    }

    /**
     * @return Collection<int, WorkflowTask>
     */
    public function openTasks(): Collection
    {
        return $this->instance()?->openTasks()->get() ?? collect();
    }

    public function cancel(?Authenticatable $actor = null, ?string $reason = null): void
    {
        $this->engine->cancel($this->instanceOrFail(), $actor, $reason);
    }

    private function instanceOrFail(): WorkflowInstance
    {
        return $this->instance() ?? throw new WorkflowException(
            'This '.class_basename($this->subject).' has not started the ['.$this->definition()->name.'] workflow.',
        );
    }
}
