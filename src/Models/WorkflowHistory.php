<?php

declare(strict_types=1);

namespace Atannex\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of state, never edited: who, when, from where to where, why.
 *
 * @property string $transition
 * @property string|null $from_state
 * @property string $to_state
 * @property int|null $actor_id
 * @property string $actor_kind
 * @property string|null $comment
 * @property array<string, mixed>|null $input
 * @property int|null $seconds_in_state
 */
class WorkflowHistory extends Model
{
    use UsesWorkflowTables;

    /** Recorded when an instance starts. */
    public const START = '__start';

    /** Recorded when an outside change is reconciled. */
    public const SYNC = '__sync';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'input' => 'array',
        'round' => 'integer',
        'seconds_in_state' => 'integer',
    ];

    protected static function tableKey(): string
    {
        return 'history';
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(static::userModel(), 'actor_id');
    }

    /**
     * The transition's label, or what a start or sync entry means.
     */
    public function label(): string
    {
        return match ($this->transition) {
            self::START => __('Started'),
            self::SYNC => __('Changed outside the workflow'),
            default => $this->instance?->definition()->transitions[$this->transition]->label ?? $this->transition,
        };
    }
}
