<?php

declare(strict_types=1);

namespace Atannex\Workflow\Concerns;

use Atannex\Workflow\Engine\WorkflowEngine;
use Atannex\Workflow\Models\WorkflowInstance;
use Atannex\Workflow\Support\SubjectWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

/**
 * Puts a model in its workflows: instances start when it is created, outside
 * changes are reconciled when it is saved, trashing pauses the workflow and
 * restoring resumes it, and deleting it for good removes the instances.
 *
 *     $post->workflow()->apply('submit', $user, ['comment' => 'Ready']);
 *     $post->workflow()->state();         // "pending_review"
 *     $post->workflow()->available($user); // what $user may do now
 *
 * @mixin Model
 */
trait HasWorkflow
{
    public static function bootHasWorkflow(): void
    {
        $engine = fn (): WorkflowEngine => app(WorkflowEngine::class);

        static::saved(function (Model $model) use ($engine) {
            if (WorkflowEngine::isWriting() || ($model->wasRecentlyCreated === false && ! config('workflow.reconcile_external_changes', true))) {
                return;
            }

            $engine()->reconcile($model, Auth::user());
        });

        static::deleted(function (Model $model) use ($engine) {
            WorkflowEngine::isSoftDeleting($model) ? $engine()->suspend($model) : $engine()->forget($model);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $model) => $engine()->resume($model));
        }

        if (method_exists(static::class, 'forceDeleted')) {
            static::forceDeleted(fn (Model $model) => $engine()->forget($model));
        }
    }

    public function workflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowInstance::class, 'subject');
    }

    /**
     * The model's (only, or named) workflow.
     */
    public function workflow(?string $name = null): SubjectWorkflow
    {
        return new SubjectWorkflow(app(WorkflowEngine::class), $this, $name);
    }

    /**
     * Models currently in one of these states.
     */
    public function scopeInWorkflowState(Builder $query, string|array $states, ?string $workflow = null): Builder
    {
        $workflow ??= app(WorkflowEngine::class)->definition($this)->name;

        return $query->whereHas('workflowInstances', fn (Builder $q) => $q
            ->where('workflow', $workflow)
            ->whereIn('state', (array) $states));
    }
}
