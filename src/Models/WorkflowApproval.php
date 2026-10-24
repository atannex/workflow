<?php

declare(strict_types=1);

namespace Atannex\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's vote on a transition that needs approvals.
 */
class WorkflowApproval extends Model
{
    use UsesWorkflowTables;

    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    protected $guarded = ['id'];

    protected $casts = ['round' => 'integer'];

    protected static function tableKey(): string
    {
        return 'approvals';
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(static::userModel(), 'user_id');
    }
}
