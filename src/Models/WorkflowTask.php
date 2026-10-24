<?php

declare(strict_types=1);

namespace Atannex\Workflow\Models;

use Atannex\Workflow\Support\Assignees;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Work waiting for a user, a role or a permission while an instance sits
 * in a state.
 *
 * @property int $id
 * @property int $instance_id
 * @property string $state
 * @property int $round
 * @property string $name
 * @property string|null $label
 * @property string $assignee_type
 * @property string $assignee
 * @property int|null $claimed_by
 * @property string $status
 * @property Carbon|null $due_at
 * @property Carbon|null $overdue_at
 * @property int $escalation_level
 * @property array<string, mixed>|null $meta
 */
class WorkflowTask extends Model
{
    use UsesWorkflowTables;

    public const OPEN = 'open';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected $casts = [
        'round' => 'integer',
        'escalation_level' => 'integer',
        'due_at' => 'datetime',
        'overdue_at' => 'datetime',
        'completed_at' => 'datetime',
        'meta' => 'array',
    ];

    protected $attributes = [
        'status' => self::OPEN,
        'escalation_level' => 0,
    ];

    protected static function tableKey(): string
    {
        return 'tasks';
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    public function claimer(): BelongsTo
    {
        return $this->belongsTo(static::userModel(), 'claimed_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(static::userModel(), 'completed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->isPast();
    }

    /**
     * Assigned to this user (directly or through a role or permission), and
     * not claimed by someone else.
     */
    public function isFor(Authenticatable $user): bool
    {
        if ($this->claimed_by !== null) {
            return (string) $this->claimed_by === (string) $user->getAuthIdentifier();
        }

        return app(Assignees::class)->matches($this->assignee_type, $this->assignee, $user);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    /**
     * Open tasks this user may work on: assigned to them, their roles or
     * permissions and unclaimed, or claimed by them.
     */
    public function scopeFor(Builder $query, Authenticatable $user): Builder
    {
        $id = $user->getAuthIdentifier();

        return $query->open()->where(fn (Builder $query) => $query
            ->where('claimed_by', $id)
            ->orWhere(fn (Builder $q) => app(Assignees::class)->scope($q->whereNull('claimed_by'), $user)));
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_at')->where('due_at', '<=', now());
    }

    public function assigneeLabel(): string
    {
        return match ($this->assignee_type) {
            Assignees::USER => optional(static::userModel()::find($this->assignee))->name ?? __('User #:id', ['id' => $this->assignee]),
            Assignees::ROLE => __('Role: :name', ['name' => $this->assignee]),
            default => __('Permission: :name', ['name' => $this->assignee]),
        };
    }
}
