<?php

declare(strict_types=1);

namespace Atannex\Workflow\Models;

use Atannex\Workflow\Definition\State;
use Atannex\Workflow\Definition\WorkflowDefinition;
use Atannex\Workflow\WorkflowRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One subject going through one workflow.
 *
 * @property int $id
 * @property string $workflow
 * @property string $subject_type
 * @property string $subject_id
 * @property string $state
 * @property string $status
 * @property int $round
 * @property array<string, mixed>|null $context
 * @property Carbon|null $state_entered_at
 * @property Carbon|null $timer_at
 * @property string|null $timer_transition
 * @property Carbon|null $completed_at
 */
class WorkflowInstance extends Model
{
    use UsesWorkflowTables;

    public const ACTIVE = 'active';

    /** The subject is in the trash; nothing happens until it is restored. */
    public const SUSPENDED = 'suspended';

    /** In a final state. */
    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected $casts = [
        'context' => 'array',
        'round' => 'integer',
        'state_entered_at' => 'datetime',
        'timer_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::ACTIVE,
        'round' => 1,
    ];

    protected static function tableKey(): string
    {
        return 'instances';
    }

    public function subject(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes();
    }

    public function history(): HasMany
    {
        return $this->hasMany(WorkflowHistory::class, 'instance_id')->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkflowTask::class, 'instance_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(WorkflowApproval::class, 'instance_id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(static::userModel(), 'started_by');
    }

    public function definition(): WorkflowDefinition
    {
        return app(WorkflowRegistry::class)->get($this->workflow);
    }

    public function currentState(): State
    {
        return $this->definition()->getState($this->state);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * Open tasks for the current visit to the state.
     */
    public function openTasks(): HasMany
    {
        return $this->tasks()->where('status', WorkflowTask::OPEN);
    }

    /**
     * Votes cast for a transition in the current visit to the state.
     */
    public function votes(string $transition): HasMany
    {
        return $this->approvals()->where('transition', $transition)->where('round', $this->round);
    }

    /**
     * Approvals cast for a transition in this visit to its state.
     */
    public function approvalCount(string $transition): int
    {
        return $this->votes($transition)->where('decision', WorkflowApproval::APPROVE)->count();
    }

    /**
     * Whether a user has approved the transition in this visit.
     */
    public function approvedBy(string $transition, int|string|null $userId): bool
    {
        return $userId !== null && $this->votes($transition)
            ->where('user_id', $userId)
            ->where('decision', WorkflowApproval::APPROVE)
            ->exists();
    }

    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query->where('subject_type', $subject->getMorphClass())->where('subject_id', (string) $subject->getKey());
    }

    public function scopeInState(Builder $query, string ...$states): Builder
    {
        return $query->whereIn('state', $states);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }
}
