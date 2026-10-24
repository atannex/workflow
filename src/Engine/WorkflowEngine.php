<?php

declare(strict_types=1);

namespace Atannex\Workflow\Engine;

use Atannex\Workflow\Definition\State;
use Atannex\Workflow\Definition\Transition;
use Atannex\Workflow\Definition\WorkflowDefinition;
use Atannex\Workflow\Events\ApprovalRecorded;
use Atannex\Workflow\Events\StateEntered;
use Atannex\Workflow\Events\TaskAssigned;
use Atannex\Workflow\Events\TaskCompleted;
use Atannex\Workflow\Events\TaskOverdue;
use Atannex\Workflow\Events\Transitioned;
use Atannex\Workflow\Events\Transitioning;
use Atannex\Workflow\Events\WorkflowCancelled;
use Atannex\Workflow\Events\WorkflowCompleted;
use Atannex\Workflow\Events\WorkflowStarted;
use Atannex\Workflow\Exceptions\StaleState;
use Atannex\Workflow\Exceptions\TaskNotAllowed;
use Atannex\Workflow\Exceptions\TransitionNotAllowed;
use Atannex\Workflow\Exceptions\WorkflowException;
use Atannex\Workflow\Guards\GuardFactory;
use Atannex\Workflow\Guards\GuardResult;
use Atannex\Workflow\Models\WorkflowApproval;
use Atannex\Workflow\Models\WorkflowHistory;
use Atannex\Workflow\Models\WorkflowInstance;
use Atannex\Workflow\Models\WorkflowTask;
use Atannex\Workflow\Support\Actor;
use Atannex\Workflow\Support\Assignees;
use Atannex\Workflow\Support\Duration;
use Atannex\Workflow\Support\Values;
use Atannex\Workflow\WorkflowRegistry;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs workflows: starts instances, checks and applies transitions, counts
 * votes, hands out and closes tasks, fires timers and deadlines, and keeps
 * subjects and instances in step.
 *
 * Every change happens in one database transaction with the instance row
 * locked, so two people acting at once cannot both move the same subject;
 * effects that throw roll the whole change back. Events are dispatched
 * after the transaction commits, so listeners never see a change that was
 * rolled back.
 */
final class WorkflowEngine
{
    /** Depth of engine-made subject saves (reconciliation ignores them). */
    private static int $writing = 0;

    private int $depth = 0;

    /** Set while runAutomatic() loops, so nested applies leave the chaining to it. */
    private bool $automating = false;

    /** @var array<int, object> */
    private array $buffered = [];

    public function __construct(
        private readonly WorkflowRegistry $registry,
        private readonly GuardFactory $guards,
        private readonly Assignees $assignees,
        private readonly Dispatcher $events,
        private readonly ConnectionResolverInterface $db,
        private readonly ValidationFactory $validator,
        private readonly int $maxAutomaticHops = 10,
        private readonly int $batch = 500,
    ) {}

    public static function isWriting(): bool
    {
        return self::$writing > 0;
    }

    public function registry(): WorkflowRegistry
    {
        return $this->registry;
    }

    public function definition(Model|string $subject, ?string $workflow = null): WorkflowDefinition
    {
        return $this->registry->resolve($subject, $workflow);
    }

    // ------------------------------------------------------------------
    // Instances
    // ------------------------------------------------------------------

    public function instance(Model $subject, ?string $workflow = null): ?WorkflowInstance
    {
        if (! $subject->exists) {
            return null;
        }

        $definition = $this->definition($subject, $workflow);

        return WorkflowInstance::query()
            ->forSubject($subject)
            ->where('workflow', $definition->name)
            ->first();
    }

    /**
     * The subject's instance, started on first use when the workflow
     * auto-starts (subjects that existed before the workflow did).
     */
    public function instanceFor(Model $subject, ?string $workflow = null, Authenticatable|Actor|null $actor = null): ?WorkflowInstance
    {
        $instance = $this->instance($subject, $workflow);

        if ($instance === null && $subject->exists && $this->definition($subject, $workflow)->autoStart) {
            $instance = $this->start($subject, $workflow, $actor);
        }

        return $instance;
    }

    /**
     * Start a subject in a workflow (a no-op returning the existing instance
     * when it already has one). The state is the one given, else the one
     * inferred from the subject, else the initial state.
     *
     * @param  array<string, mixed>  $context
     */
    public function start(Model $subject, ?string $workflow = null, Authenticatable|Actor|null $actor = null, ?string $state = null, array $context = []): WorkflowInstance
    {
        if (! $subject->exists) {
            throw new WorkflowException('Save the subject before starting a workflow on it.');
        }

        $definition = $this->definition($subject, $workflow);
        $actor = Actor::from($actor);

        if (($existing = $this->instance($subject, $definition->name)) !== null) {
            return $existing;
        }

        $state ??= $this->infer($definition, $subject) ?? $definition->initial;

        if (! $definition->hasState($state)) {
            throw new WorkflowException("Workflow [{$definition->name}] has no state [{$state}].");
        }

        try {
            $instance = $this->transaction(function () use ($definition, $subject, $actor, $state, $context) {
                $instance = WorkflowInstance::query()->create([
                    'workflow' => $definition->name,
                    'subject_type' => $subject->getMorphClass(),
                    'subject_id' => (string) $subject->getKey(),
                    'state' => $state,
                    'status' => $definition->isFinal($state) ? WorkflowInstance::COMPLETED : WorkflowInstance::ACTIVE,
                    'round' => 1,
                    'context' => $context === [] ? null : $context,
                    'started_by' => $actor->id(),
                    'state_entered_at' => now(),
                    'completed_at' => $definition->isFinal($state) ? now() : null,
                ]);

                $instance->setRelation('subject', $subject);

                $this->record($instance, WorkflowHistory::START, null, $state, $actor);

                $context = new Context($definition, $instance, $subject, $actor, null, null, $state);
                $this->enter($context, $definition->getState($state));
                $instance->save();

                $this->buffer(new WorkflowStarted($instance, $subject, $actor));
                $this->buffer(new StateEntered($instance, $subject, $state, null, $actor));

                return $instance;
            });
        } catch (UniqueConstraintViolationException) {
            // Someone else started it at the same moment.
            return $this->instance($subject, $definition->name) ?? throw new WorkflowException('Could not start the workflow.');
        }

        $this->runAutomatic($instance);

        return $instance->refresh();
    }

    public function state(Model $subject, ?string $workflow = null): ?string
    {
        return $this->instanceFor($subject, $workflow)?->state;
    }

    // ------------------------------------------------------------------
    // Transitions
    // ------------------------------------------------------------------

    /**
     * Take a transition. Options: "comment", "input" (validated against the
     * transition's rules), "expect" (the state the caller saw; a different
     * current state throws StaleState).
     *
     * For transitions that need approvals this casts an approving vote and
     * only moves the subject once enough approvals are in.
     *
     * @param  array{comment?: ?string, input?: array<string, mixed>, expect?: ?string}  $options
     *
     * @throws TransitionNotAllowed|StaleState|ValidationException
     */
    public function apply(Model|WorkflowInstance $target, string $transition, Authenticatable|Actor|null $actor = null, array $options = []): Outcome
    {
        return $this->act($target, $transition, Actor::from($actor), $options, WorkflowApproval::APPROVE);
    }

    /**
     * Vote to approve a transition that needs approvals (same as apply()).
     *
     * @param  array{comment?: ?string, input?: array<string, mixed>, expect?: ?string}  $options
     */
    public function approve(Model|WorkflowInstance $target, string $transition, Authenticatable $user, array $options = []): Outcome
    {
        return $this->act($target, $transition, Actor::user($user), $options, WorkflowApproval::APPROVE);
    }

    /**
     * Vote against a transition that needs approvals.
     *
     * @param  array{comment?: ?string, expect?: ?string}  $options
     */
    public function reject(Model|WorkflowInstance $target, string $transition, Authenticatable $user, array $options = []): Outcome
    {
        return $this->act($target, $transition, Actor::user($user), $options, WorkflowApproval::REJECT);
    }

    /**
     * Whether an actor may take a transition now, and if not, why.
     */
    public function check(Model|WorkflowInstance $target, string $transition, Authenticatable|Actor|null $actor = null): GuardResult
    {
        $instance = $this->resolveInstance($target);

        if ($instance === null) {
            return GuardResult::deny(__('This item is not in a workflow.'));
        }

        $definition = $instance->definition();

        if (! isset($definition->transitions[$transition])) {
            return GuardResult::deny(__('Unknown step.'));
        }

        $subject = $this->subjectOf($instance);

        $context = new Context($definition, $instance, $subject, Actor::from($actor), $definition->transitions[$transition], $instance->state, $definition->transitions[$transition]->to);

        return $this->evaluate($context);
    }

    public function can(Model|WorkflowInstance $target, string $transition, Authenticatable|Actor|null $actor = null): bool
    {
        return $this->check($target, $transition, $actor)->allowed;
    }

    /**
     * Transitions the actor may take now, in definition order (only those
     * offered to people: not system, automatic or hidden ones).
     *
     * @return array<string, Transition>
     */
    public function available(Model|WorkflowInstance $target, Authenticatable|Actor|null $actor = null): array
    {
        $instance = $this->resolveInstance($target);

        if ($instance === null || ! $instance->isActive() && $instance->status !== WorkflowInstance::COMPLETED) {
            return [];
        }

        $definition = $instance->definition();
        $subject = $this->subjectOf($instance);
        $actor = Actor::from($actor);

        return array_filter(
            $definition->transitionsFrom($instance->state),
            fn (Transition $transition) => $transition->offeredToUsers()
                && $this->evaluate(new Context($definition, $instance, $subject, $actor, $transition, $instance->state, $transition->to))->allowed,
        );
    }

    /**
     * @param  array{comment?: ?string, input?: array<string, mixed>, expect?: ?string}  $options
     */
    private function act(Model|WorkflowInstance $target, string $transition, Actor $actor, array $options, string $decision): Outcome
    {
        $instance = $this->resolveInstance($target) ?? throw new TransitionNotAllowed($transition, [__('This item is not in a workflow.')]);

        $outcome = $this->transaction(function () use ($instance, $transition, $actor, $options, $decision) {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()->lockForUpdate()->findOrFail($instance->getKey());
            $definition = $instance->definition();
            $definitionTransition = $definition->getTransition($transition);
            $subject = $this->subjectOf($instance);

            $expect = $options['expect'] ?? null;

            if ($expect !== null && $expect !== $instance->state) {
                throw new StaleState($expect, $instance->state);
            }

            $comment = isset($options['comment']) && trim((string) $options['comment']) !== '' ? trim((string) $options['comment']) : null;

            $context = new Context($definition, $instance, $subject, $actor, $definitionTransition, $instance->state, $definitionTransition->to, $comment, $options['input'] ?? []);

            if ($decision === WorkflowApproval::REJECT && $definitionTransition->approval === null) {
                throw new TransitionNotAllowed($transition, [__('This step is not decided by a vote.')]);
            }

            $verdict = $this->evaluate($context, $decision === WorkflowApproval::REJECT);

            if ($verdict->denied()) {
                throw new TransitionNotAllowed($transition, $verdict->reasons);
            }

            if ($decision === WorkflowApproval::APPROVE) {
                $context = $this->validated($context);
            }

            if ($definitionTransition->approval !== null) {
                return $this->vote($context, $decision);
            }

            return $this->move($context);
        });

        if ($outcome->status !== Outcome::PENDING) {
            $this->runAutomatic($outcome->instance);
        }

        return $outcome;
    }

    /**
     * Status, source state, who may take it (or vote on it) and the
     * transition's guards.
     */
    private function evaluate(Context $context, bool $rejecting = false): GuardResult
    {
        $instance = $context->instance;
        $transition = $context->transition;
        $definition = $context->definition;

        if (in_array($instance->status, [WorkflowInstance::SUSPENDED, WorkflowInstance::CANCELLED], true)) {
            return GuardResult::deny(__('This workflow is :status.', ['status' => __($instance->status)]));
        }

        if (! isset($definition->transitionsFrom($instance->state)[$transition->name])) {
            return GuardResult::deny(__('Not possible while it is :state.', ['state' => $definition->getState($instance->state)->label]));
        }

        if ($transition->system && ! $context->isSystem()) {
            return GuardResult::deny(__('This step happens automatically.'));
        }

        $approval = $transition->approval;

        if ($approval !== null && $context->isSystem()) {
            return GuardResult::deny(__('This step needs people to approve it.'));
        }

        $guards = $approval !== null && $approval->voters !== [] ? $approval->voters : $transition->guards;
        $result = $this->guards->check($guards, $context);

        if ($result->allowed && $approval !== null && $instance->exists) {
            $vote = $instance->votes($transition->name)->where('user_id', $context->actor->id())->value('decision');

            if ($vote === ($rejecting ? WorkflowApproval::REJECT : WorkflowApproval::APPROVE)) {
                return GuardResult::deny($rejecting ? __('You have already rejected this.') : __('You have already approved this.'));
            }
        }

        return $result;
    }

    /**
     * Required comment and input rules.
     */
    private function validated(Context $context): Context
    {
        $transition = $context->transition;

        $rules = $transition->rules;

        if ($transition->requiresComment) {
            $rules['comment'] = ['required', 'string', 'max:5000'];
        }

        if ($rules === []) {
            return $context;
        }

        $data = $this->validator->make([...$context->input, 'comment' => $context->comment], $rules)->validate();
        unset($data['comment']);

        return new Context($context->definition, $context->instance, $context->subject, $context->actor, $transition, $context->from, $context->to, $context->comment, [...$context->input, ...$data]);
    }

    private function vote(Context $context, string $decision): Outcome
    {
        $instance = $context->instance;
        $transition = $context->transition;
        $rule = $transition->approval;

        if ($context->user() === null) {
            throw new TransitionNotAllowed($transition->name, [__('Only a signed-in user can vote.')]);
        }

        if ($decision === WorkflowApproval::REJECT && $rule->commentOnReject && $context->comment === null) {
            throw ValidationException::withMessages(['comment' => __('Say why you reject it.')]);
        }

        $approval = WorkflowApproval::query()->updateOrCreate(
            ['instance_id' => $instance->getKey(), 'transition' => $transition->name, 'round' => $instance->round, 'user_id' => $context->actor->id()],
            ['decision' => $decision, 'comment' => $context->comment],
        );

        $votes = $instance->votes($transition->name)->pluck('decision');
        $approvals = $votes->filter(fn ($vote) => $vote === WorkflowApproval::APPROVE)->count();
        $rejections = $votes->filter(fn ($vote) => $vote === WorkflowApproval::REJECT)->count();

        $this->buffer(new ApprovalRecorded($approval, $instance, $approvals, $rule->required));

        if ($decision === WorkflowApproval::REJECT && $rule->rejectTransition !== null && $rejections >= $rule->rejections) {
            $reject = $context->definition->getTransition($rule->rejectTransition);
            $outcome = $this->move($context->withTransition($reject, $context->comment));

            return new Outcome(Outcome::REJECTED, $outcome->instance, $reject->name, $outcome->from, $outcome->state, $approvals, $rule->required);
        }

        if ($decision === WorkflowApproval::APPROVE && $approvals >= $rule->required) {
            $outcome = $this->move($context);

            return new Outcome(Outcome::APPLIED, $outcome->instance, $transition->name, $outcome->from, $outcome->state, $approvals, $rule->required);
        }

        return new Outcome(Outcome::PENDING, $instance, $transition->name, $instance->state, $instance->state, $approvals, $rule->required);
    }

    /**
     * Change the state: leave hooks, effects, history, tasks, timers.
     */
    private function move(Context $context): Outcome
    {
        $instance = $context->instance;
        $definition = $context->definition;
        $transition = $context->transition;
        $from = $instance->state;
        $to = $transition->to;

        $event = new Transitioning($context);
        $this->events->dispatch($event);

        if ($event->blockedBecause !== null) {
            throw new TransitionNotAllowed($transition->name, [$event->blockedBecause]);
        }

        $this->callAll($definition->getState($from)->onLeave, $context);
        $this->callAll($transition->before, $context);

        $this->leave($context);
        $entry = $this->changeState($context, $transition->name, $to);

        $this->enter($context, $definition->getState($to));
        $this->callAll($transition->after, $context);

        $this->saveSubject($context->subject);
        $instance->save();

        $this->buffer(new Transitioned($context, $entry));
        $this->buffer(new StateEntered($instance, $context->subject, $to, $from, $context->actor));

        if ($instance->status === WorkflowInstance::COMPLETED && $definition->isFinal($to)) {
            $this->buffer(new WorkflowCompleted($instance, $context->subject));
        }

        return new Outcome(Outcome::APPLIED, $instance, $transition->name, $from, $to);
    }

    private function changeState(Context $context, string $transition, string $to): WorkflowHistory
    {
        $instance = $context->instance;
        $definition = $context->definition;
        $from = $instance->state;

        $entry = $this->record($instance, $transition, $from, $to, $context->actor, $context->comment, $context->input);

        $instance->forceFill([
            'state' => $to,
            'round' => $instance->round + 1,
            'state_entered_at' => now(),
            'status' => $definition->isFinal($to) ? WorkflowInstance::COMPLETED : WorkflowInstance::ACTIVE,
            'completed_at' => $definition->isFinal($to) ? now() : null,
            'timer_at' => null,
            'timer_transition' => null,
        ]);

        return $entry;
    }

    /**
     * Close the work of the state being left: tasks the actor could do are
     * completed by them, the rest cancelled.
     */
    private function leave(Context $context): void
    {
        $user = $context->user();

        $context->instance->openTasks()->get()->each(function (WorkflowTask $task) use ($user) {
            $doneByActor = $user !== null && $task->isFor($user);

            $task->forceFill([
                'status' => $doneByActor ? WorkflowTask::COMPLETED : WorkflowTask::CANCELLED,
                'completed_by' => $doneByActor ? $user->getAuthIdentifier() : null,
                'completed_at' => now(),
            ])->save();
        });
    }

    /**
     * Arrive in a state: mirror column, tasks, timer, enter hooks.
     */
    private function enter(Context $context, State $state): void
    {
        $definition = $context->definition;
        $subject = $context->subject;
        $instance = $context->instance;

        if ($definition->column !== null && Values::string($subject->getAttribute($definition->column)) !== $state->name) {
            $subject->setAttribute($definition->column, $state->name);
        }

        // Only a transition is an edit of the subject; mirroring the state on
        // start, sync or backfill leaves its updated_at alone.
        $this->saveSubject($subject, touch: $context->transition !== null);

        if ($instance->status !== WorkflowInstance::SUSPENDED) {
            $this->handOutTasks($context, $state);
        }

        $this->scheduleTimer($context);
        $this->callAll($state->onEnter, $context);
    }

    private function handOutTasks(Context $context, State $state, ?string $only = null): void
    {
        $instance = $context->instance;

        foreach ($state->tasks as $spec) {
            if ($only !== null && $spec->name !== $only) {
                continue;
            }

            foreach ($this->assignees->resolve($spec->assignees, $context) as [$type, $assignee]) {
                $task = $instance->tasks()->create([
                    'state' => $state->name,
                    'round' => $instance->round,
                    'name' => $spec->name,
                    'label' => $spec->label,
                    'assignee_type' => $type,
                    'assignee' => $assignee,
                    'due_at' => $spec->due !== null ? Duration::after(now(), $spec->due) : null,
                    'meta' => $spec->meta === [] ? null : $spec->meta,
                ]);

                $this->buffer(new TaskAssigned($task, $instance));
            }
        }
    }

    /**
     * The earliest timer of the current state (later than $after, when given).
     */
    private function scheduleTimer(Context $context, ?CarbonInterface $after = null): void
    {
        $instance = $context->instance;
        $state = $context->definition->getState($instance->state);
        $enteredAt = $instance->state_entered_at ?? now();
        $next = null;

        foreach ($state->timers as $timer) {
            $due = $timer->dueAt($context, $enteredAt);

            if ($due !== null && ($after === null || $due->gt($after)) && ($next === null || $due->lt($next[0]))) {
                $next = [$due, $timer->transition];
            }
        }

        $instance->forceFill([
            'timer_at' => $next[0] ?? null,
            'timer_transition' => $next[1] ?? null,
        ]);
    }

    // ------------------------------------------------------------------
    // Automatic transitions, timers and deadlines
    // ------------------------------------------------------------------

    /**
     * Take automatic transitions whose guards pass, until none does.
     */
    public function runAutomatic(WorkflowInstance $instance): int
    {
        if ($this->automating) {
            return 0;
        }

        $this->automating = true;

        try {
            return $this->chainAutomatic($instance);
        } finally {
            $this->automating = false;
        }
    }

    private function chainAutomatic(WorkflowInstance $instance): int
    {
        $taken = 0;

        for ($hop = 0; $hop < $this->maxAutomaticHops; $hop++) {
            $instance = $instance->fresh();

            if ($instance === null || ! $instance->isActive() && $instance->status !== WorkflowInstance::COMPLETED) {
                break;
            }

            $definition = $instance->definition();
            $subject = $this->subjectOf($instance);
            $next = null;

            foreach ($definition->transitionsFrom($instance->state) as $transition) {
                if ($transition->automatic && $this->evaluate(new Context($definition, $instance, $subject, Actor::system(), $transition, $instance->state, $transition->to))->allowed) {
                    $next = $transition;
                    break;
                }
            }

            if ($next === null) {
                break;
            }

            try {
                $this->apply($instance, $next->name, Actor::system(), ['expect' => $instance->state]);
                $taken++;
            } catch (StaleState|TransitionNotAllowed) {
                break;
            }
        }

        if ($taken === $this->maxAutomaticHops) {
            Log::warning("Workflow: instance #{$instance->getKey()} took {$taken} automatic transitions in a row; stopped (workflow.max_automatic_hops).");
        }

        return $taken;
    }

    /**
     * One scheduler run: due timers, automatic transitions and overdue tasks.
     *
     * @return array{timers: int, automatic: int, overdue: int, escalated: int, failed: int}
     */
    public function tick(): array
    {
        $report = ['timers' => 0, 'automatic' => 0, 'overdue' => 0, 'escalated' => 0, 'failed' => 0];

        WorkflowInstance::query()
            ->active()
            ->whereNotNull('timer_at')
            ->where('timer_at', '<=', now())
            ->orderBy('timer_at')
            ->limit($this->batch)
            ->get()
            ->each(function (WorkflowInstance $instance) use (&$report) {
                $this->fireTimer($instance) ? $report['timers']++ : $report['failed']++;
            });

        foreach ($this->registry->all() as $definition) {
            $states = collect($definition->transitions)
                ->filter(fn (Transition $transition) => $transition->automatic)
                ->flatMap(fn (Transition $transition) => in_array('*', $transition->from, true) ? array_keys($definition->states) : $transition->from)
                ->unique()
                ->values()
                ->all();

            if ($states === []) {
                continue;
            }

            WorkflowInstance::query()
                ->where('workflow', $definition->name)
                ->whereIn('status', [WorkflowInstance::ACTIVE, WorkflowInstance::COMPLETED])
                ->whereIn('state', $states)
                ->limit($this->batch)
                ->get()
                ->each(function (WorkflowInstance $instance) use (&$report) {
                    $report['automatic'] += $this->guarded(fn () => $this->runAutomatic($instance), $instance) ?? 0;
                });
        }

        WorkflowTask::query()
            ->overdue()
            ->whereNull('overdue_at')
            ->with('instance')
            ->limit($this->batch)
            ->get()
            ->each(function (WorkflowTask $task) use (&$report) {
                $report['overdue']++;
                $report['escalated'] += $this->guarded(fn () => $this->handleOverdue($task), $task->instance) ?? 0;
            });

        return $report;
    }

    private function fireTimer(WorkflowInstance $instance): bool
    {
        $transition = $instance->timer_transition;

        try {
            $this->apply($instance, $transition, Actor::system(), ['expect' => $instance->state]);

            return true;
        } catch (StaleState) {
            return true;
        } catch (TransitionNotAllowed $e) {
            // The guards say no (e.g. a condition no longer holds): move on to
            // the state's next timer instead of retrying this one forever.
            $this->transaction(function () use ($instance) {
                $instance = WorkflowInstance::query()->lockForUpdate()->find($instance->getKey());

                if ($instance === null) {
                    return;
                }

                $this->scheduleTimer($this->contextFor($instance), now());
                $instance->save();
            });

            Log::info("Workflow: timer [{$transition}] on instance #{$instance->getKey()} skipped: ".$e->getMessage());

            return false;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function handleOverdue(WorkflowTask $task): int
    {
        $instance = $task->instance;

        $escalated = $this->transaction(function () use ($task, $instance) {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()->lockForUpdate()->find($task->getKey());

            if ($task === null || $task->overdue_at !== null || ! $task->isOpen()) {
                return 0;
            }

            $task->forceFill(['overdue_at' => now(), 'escalation_level' => $task->escalation_level + 1])->save();
            $this->buffer(new TaskOverdue($task, $instance));

            $spec = $instance->definition()->states[$task->state]->tasks[$task->name] ?? null;

            if ($spec === null || $spec->escalateTo === [] || $task->round !== $instance->round || ! $instance->isActive()) {
                return 0;
            }

            $created = 0;

            foreach ($this->assignees->resolve($spec->escalateTo, $this->contextFor($instance)) as [$type, $assignee]) {
                $copy = $instance->tasks()->create([
                    'state' => $task->state,
                    'round' => $task->round,
                    'name' => $task->name,
                    'label' => $task->label,
                    'assignee_type' => $type,
                    'assignee' => $assignee,
                    'escalation_level' => $task->escalation_level,
                    'meta' => [...($task->meta ?? []), 'escalated_from' => $task->getKey()],
                ]);

                $this->buffer(new TaskAssigned($copy, $instance, escalated: true));
                $created++;
            }

            return $created;
        });

        $spec = $instance->definition()->states[$task->state]->tasks[$task->name] ?? null;

        if ($spec?->overdueTransition !== null && $instance->fresh()?->state === $task->state) {
            try {
                $this->apply($instance, $spec->overdueTransition, Actor::system(), ['expect' => $task->state]);
            } catch (StaleState|TransitionNotAllowed $e) {
                Log::info("Workflow: deadline transition [{$spec->overdueTransition}] on instance #{$instance->getKey()} skipped: ".$e->getMessage());
            }
        }

        return $escalated;
    }

    // ------------------------------------------------------------------
    // Tasks
    // ------------------------------------------------------------------

    /**
     * Take a shared task so colleagues see someone is on it.
     */
    public function claim(WorkflowTask $task, Authenticatable $user): WorkflowTask
    {
        return $this->transaction(function () use ($task, $user) {
            $task = WorkflowTask::query()->lockForUpdate()->findOrFail($task->getKey());

            if (! $task->isOpen() || ! $task->isFor($user)) {
                throw new TaskNotAllowed(__('This task is not yours to take.'));
            }

            $task->forceFill(['claimed_by' => $user->getAuthIdentifier()])->save();

            return $task;
        });
    }

    /**
     * Give a claimed task back to its group.
     */
    public function release(WorkflowTask $task, Authenticatable $user): WorkflowTask
    {
        if ((string) $task->claimed_by !== (string) $user->getAuthIdentifier()) {
            throw new TaskNotAllowed(__('Only the person who took this task can give it back.'));
        }

        $task->forceFill(['claimed_by' => null])->save();

        return $task;
    }

    /**
     * Mark a task done (with copies made by escalation), then try automatic
     * transitions: a "tasks-complete" guard joins parallel work.
     */
    public function complete(WorkflowTask $task, Authenticatable $user, ?string $comment = null): WorkflowTask
    {
        $task = $this->transaction(function () use ($task, $user, $comment) {
            $task = WorkflowTask::query()->lockForUpdate()->findOrFail($task->getKey());

            if (! $task->isOpen() || ! $task->isFor($user)) {
                throw new TaskNotAllowed(__('This task is not yours to complete.'));
            }

            WorkflowTask::query()
                ->where('instance_id', $task->instance_id)
                ->where('round', $task->round)
                ->where('name', $task->name)
                ->where('status', WorkflowTask::OPEN)
                ->update([
                    'status' => WorkflowTask::COMPLETED,
                    'completed_by' => $user->getAuthIdentifier(),
                    'completed_at' => now(),
                ]);

            $task->refresh();

            if ($comment !== null && trim($comment) !== '') {
                $task->forceFill(['meta' => [...($task->meta ?? []), 'comment' => trim($comment)]])->save();
            }

            $this->buffer(new TaskCompleted($task, $user));

            return $task;
        });

        $this->runAutomatic($task->instance);

        return $task;
    }

    /**
     * Hand a task to someone else ("user:5", "role:editor", a user…).
     */
    public function reassign(WorkflowTask $task, string|Authenticatable $assignee, Authenticatable|Actor|null $by = null): WorkflowTask
    {
        return $this->transaction(function () use ($task, $assignee, $by) {
            $task = WorkflowTask::query()->lockForUpdate()->findOrFail($task->getKey());

            if (! $task->isOpen()) {
                throw new TaskNotAllowed(__('This task is already closed.'));
            }

            $instance = $task->instance;
            $pairs = $this->assignees->resolve([$assignee instanceof Authenticatable ? $assignee : (string) $assignee], $this->contextFor($instance, Actor::from($by)));

            if ($pairs->isEmpty()) {
                throw new TaskNotAllowed(__('Nobody to hand this task to.'));
            }

            $task->forceFill(['status' => WorkflowTask::CANCELLED, 'completed_at' => now()])->save();

            $copy = null;

            foreach ($pairs as [$type, $name]) {
                $copy = $instance->tasks()->create([
                    ...$task->only(['state', 'round', 'name', 'label', 'due_at', 'escalation_level']),
                    'assignee_type' => $type,
                    'assignee' => $name,
                    'meta' => [...($task->meta ?? []), 'reassigned_from' => $task->getKey(), 'reassigned_by' => Actor::from($by)->id()],
                ]);

                $this->buffer(new TaskAssigned($copy, $instance));
            }

            return $copy;
        });
    }

    // ------------------------------------------------------------------
    // Outside changes, trash and cancellation
    // ------------------------------------------------------------------

    /**
     * After the subject was saved outside the engine: start it if new, move
     * it to the state its data now says (recorded as a sync), or reschedule
     * timers that read the subject.
     */
    public function reconcile(Model $subject, ?Authenticatable $user = null): void
    {
        if (self::isWriting()) {
            return;
        }

        foreach ($this->registry->forSubject($subject) as $definition) {
            $instance = $this->instance($subject, $definition->name);

            if ($instance === null) {
                if ($definition->autoStart) {
                    $this->start($subject, $definition->name, $user);
                }

                continue;
            }

            if (in_array($instance->status, [WorkflowInstance::SUSPENDED, WorkflowInstance::CANCELLED], true)) {
                continue;
            }

            $target = $definition->reconcileUsing !== null
                ? ($definition->reconcileUsing)($subject, $instance->state)
                : ($definition->column !== null && $subject->wasChanged($definition->column) ? Values::string($subject->getAttribute($definition->column)) : null);

            if ($definition->hasState($target) && $target !== $instance->state) {
                $this->sync($instance, $subject, $target, Actor::sync($user));

                continue;
            }

            if ($definition->getState($instance->state)->timers !== []) {
                $this->transaction(function () use ($instance) {
                    $instance = WorkflowInstance::query()->lockForUpdate()->find($instance->getKey());
                    $this->scheduleTimer($this->contextFor($instance));
                    $instance->save();
                });
            }
        }
    }

    /**
     * Move to a state without a transition (no guards, no effects), recorded
     * in the history.
     */
    public function sync(WorkflowInstance $instance, Model $subject, string $state, Actor $actor, ?string $comment = null): void
    {
        $this->transaction(function () use ($instance, $subject, $state, $actor, $comment) {
            $instance = WorkflowInstance::query()->lockForUpdate()->findOrFail($instance->getKey());
            $definition = $instance->definition();
            $from = $instance->state;
            $instance->setRelation('subject', $subject);

            $context = new Context($definition, $instance, $subject, $actor, null, $from, $state, $comment);

            $this->leave($context);
            $this->changeState($context, WorkflowHistory::SYNC, $state);
            $this->enter($context, $definition->getState($state));
            $instance->save();

            $this->buffer(new StateEntered($instance, $subject, $state, $from, $actor));

            if ($definition->isFinal($state)) {
                $this->buffer(new WorkflowCompleted($instance, $subject));
            }
        });
    }

    /**
     * The subject went to the trash: pause its instances and withdraw their tasks.
     */
    public function suspend(Model $subject): void
    {
        $this->transaction(function () use ($subject) {
            WorkflowInstance::query()->forSubject($subject)->whereIn('status', [WorkflowInstance::ACTIVE, WorkflowInstance::COMPLETED])->get()
                ->each(function (WorkflowInstance $instance) {
                    $instance->openTasks()->update(['status' => WorkflowTask::CANCELLED, 'completed_at' => now()]);
                    $instance->forceFill(['status' => WorkflowInstance::SUSPENDED, 'context' => [...($instance->context ?? []), '_suspended_from' => $instance->status]])->save();
                });
        });
    }

    /**
     * The subject came back from the trash: resume where it was, with fresh tasks.
     */
    public function resume(Model $subject): void
    {
        $this->transaction(function () use ($subject) {
            WorkflowInstance::query()->forSubject($subject)->where('status', WorkflowInstance::SUSPENDED)->get()
                ->each(function (WorkflowInstance $instance) use ($subject) {
                    $context = $instance->context ?? [];
                    $status = $context['_suspended_from'] ?? WorkflowInstance::ACTIVE;
                    unset($context['_suspended_from']);

                    $instance->forceFill(['status' => $status, 'context' => $context === [] ? null : $context, 'round' => $instance->round + 1])->save();
                    $instance->setRelation('subject', $subject);

                    $workflowContext = $this->contextFor($instance);
                    $this->handOutTasks($workflowContext, $instance->currentState());
                    $this->scheduleTimer($workflowContext);
                    $instance->save();
                });
        });
    }

    /**
     * Stop an instance for good (it stays in the history).
     */
    public function cancel(WorkflowInstance $instance, Authenticatable|Actor|null $actor = null, ?string $reason = null): void
    {
        $this->transaction(function () use ($instance, $actor, $reason) {
            $instance = WorkflowInstance::query()->lockForUpdate()->findOrFail($instance->getKey());
            $instance->openTasks()->update(['status' => WorkflowTask::CANCELLED, 'completed_at' => now()]);
            $instance->forceFill(['status' => WorkflowInstance::CANCELLED, 'timer_at' => null, 'timer_transition' => null])->save();

            $this->record($instance, '__cancel', $instance->state, $instance->state, Actor::from($actor), $reason);
            $this->buffer(new WorkflowCancelled($instance, $reason));
        });
    }

    /**
     * The subject is gone for good: drop its instances, history and tasks.
     */
    public function forget(Model $subject): void
    {
        // Children are removed explicitly: foreign keys may not be enforced (SQLite).
        $this->transaction(function () use ($subject) {
            WorkflowInstance::query()->forSubject($subject)->get()->each(function (WorkflowInstance $instance) {
                $instance->history()->delete();
                $instance->tasks()->delete();
                $instance->approvals()->delete();
                $instance->delete();
            });
        });
    }

    /**
     * @return Collection<int, WorkflowHistory>
     */
    public function history(Model $subject, ?string $workflow = null): Collection
    {
        return $this->instance($subject, $workflow)?->history()->with('actor')->get() ?? collect();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function infer(WorkflowDefinition $definition, Model $subject): ?string
    {
        if ($definition->inferUsing !== null) {
            $state = ($definition->inferUsing)($subject);

            return $definition->hasState($state) ? $state : null;
        }

        if ($definition->column !== null) {
            $state = Values::string($subject->getAttribute($definition->column));

            return $definition->hasState($state) ? $state : null;
        }

        return null;
    }

    private function resolveInstance(Model|WorkflowInstance $target): ?WorkflowInstance
    {
        return $target instanceof WorkflowInstance ? $target : $this->instanceFor($target);
    }

    private function subjectOf(WorkflowInstance $instance): Model
    {
        $subject = $instance->relationLoaded('subject') ? $instance->getRelation('subject') : null;

        if (! $subject instanceof Model) {
            $subject = $instance->subject()->first();
        }

        if (! $subject instanceof Model) {
            throw new WorkflowException("The subject of workflow instance #{$instance->getKey()} no longer exists.");
        }

        $instance->setRelation('subject', $subject);

        return $subject;
    }

    private function contextFor(WorkflowInstance $instance, ?Actor $actor = null): Context
    {
        return new Context($instance->definition(), $instance, $this->subjectOf($instance), $actor ?? Actor::system(), null, $instance->state, $instance->state);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function record(WorkflowInstance $instance, string $transition, ?string $from, string $to, Actor $actor, ?string $comment = null, array $input = []): WorkflowHistory
    {
        return $instance->history()->create([
            'transition' => $transition,
            'from_state' => $from,
            'to_state' => $to,
            'round' => $instance->round,
            'actor_id' => $actor->id(),
            'actor_kind' => $actor->kind,
            'comment' => $comment,
            'input' => $input === [] ? null : $this->serialisable($input),
            'seconds_in_state' => $from !== null && $instance->state_entered_at !== null ? (int) abs(now()->diffInSeconds($instance->state_entered_at)) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function serialisable(array $input): array
    {
        return array_map(fn ($value) => match (true) {
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof Model => $value->getKey(),
            is_array($value) => $this->serialisable($value),
            is_object($value) => method_exists($value, '__toString') ? (string) $value : get_debug_type($value),
            default => $value,
        }, $input);
    }

    private function saveSubject(Model $subject, bool $touch = true): void
    {
        if (! $subject->isDirty()) {
            return;
        }

        self::$writing++;

        try {
            $touch || ! $subject->usesTimestamps()
                ? $subject->save()
                : $subject->withoutTimestamps(fn () => $subject->save());
        } finally {
            self::$writing--;
        }
    }

    /**
     * @param  array<int, Closure(Context): void>  $callbacks
     */
    private function callAll(array $callbacks, Context $context): void
    {
        foreach ($callbacks as $callback) {
            $callback($context);
        }
    }

    /**
     * Run in a transaction; events buffered inside are dispatched once the
     * outermost engine transaction commits (and dropped if it rolls back).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function transaction(Closure $callback): mixed
    {
        $this->depth++;

        try {
            $result = $this->db->connection(config('workflow.connection'))->transaction(fn () => self::writing($callback));
        } catch (Throwable $e) {
            if (--$this->depth === 0) {
                $this->buffered = [];
            }

            throw $e;
        }

        if (--$this->depth === 0) {
            $events = $this->buffered;
            $this->buffered = [];

            foreach ($events as $event) {
                $this->events->dispatch($event);
            }
        }

        return $result;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private static function writing(Closure $callback): mixed
    {
        self::$writing++;

        try {
            return $callback();
        } finally {
            self::$writing--;
        }
    }

    private function buffer(object $event): void
    {
        $this->buffered[] = $event;
    }

    /**
     * Run one tick step for an instance; a failure is reported and does not
     * stop the rest of the run.
     */
    private function guarded(Closure $callback, ?WorkflowInstance $instance): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Log::warning('Workflow: tick failed for instance #'.($instance?->getKey() ?? '?').': '.$e->getMessage());
            report($e);

            return null;
        }
    }

    /**
     * Whether the subject uses soft deletes and is being trashed (not removed).
     */
    public static function isSoftDeleting(Model $subject): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($subject), true)
            && ! (method_exists($subject, 'isForceDeleting') && $subject->isForceDeleting());
    }
}
