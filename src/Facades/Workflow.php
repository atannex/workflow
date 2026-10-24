<?php

declare(strict_types=1);

namespace Atannex\Workflow\Facades;

use Atannex\Workflow\Engine\WorkflowEngine;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Atannex\Workflow\WorkflowRegistry registry()
 * @method static \Atannex\Workflow\Definition\WorkflowDefinition definition(\Illuminate\Database\Eloquent\Model|string $subject, ?string $workflow = null)
 * @method static \Atannex\Workflow\Models\WorkflowInstance|null instance(\Illuminate\Database\Eloquent\Model $subject, ?string $workflow = null)
 * @method static \Atannex\Workflow\Models\WorkflowInstance|null instanceFor(\Illuminate\Database\Eloquent\Model $subject, ?string $workflow = null, mixed $actor = null)
 * @method static \Atannex\Workflow\Models\WorkflowInstance start(\Illuminate\Database\Eloquent\Model $subject, ?string $workflow = null, mixed $actor = null, ?string $state = null, array $context = [])
 * @method static string|null state(\Illuminate\Database\Eloquent\Model $subject, ?string $workflow = null)
 * @method static \Atannex\Workflow\Engine\Outcome apply(\Illuminate\Database\Eloquent\Model|\Atannex\Workflow\Models\WorkflowInstance $target, string $transition, mixed $actor = null, array $options = [])
 * @method static \Atannex\Workflow\Engine\Outcome approve(\Illuminate\Database\Eloquent\Model|\Atannex\Workflow\Models\WorkflowInstance $target, string $transition, \Illuminate\Contracts\Auth\Authenticatable $user, array $options = [])
 * @method static \Atannex\Workflow\Engine\Outcome reject(\Illuminate\Database\Eloquent\Model|\Atannex\Workflow\Models\WorkflowInstance $target, string $transition, \Illuminate\Contracts\Auth\Authenticatable $user, array $options = [])
 * @method static \Atannex\Workflow\Guards\GuardResult check(\Illuminate\Database\Eloquent\Model|\Atannex\Workflow\Models\WorkflowInstance $target, string $transition, mixed $actor = null)
 * @method static bool can(\Illuminate\Database\Eloquent\Model|\Atannex\Workflow\Models\WorkflowInstance $target, string $transition, mixed $actor = null)
 * @method static array available(\Illuminate\Database\Eloquent\Model|\Atannex\Workflow\Models\WorkflowInstance $target, mixed $actor = null)
 * @method static array tick()
 * @method static \Atannex\Workflow\Models\WorkflowTask claim(\Atannex\Workflow\Models\WorkflowTask $task, \Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \Atannex\Workflow\Models\WorkflowTask release(\Atannex\Workflow\Models\WorkflowTask $task, \Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \Atannex\Workflow\Models\WorkflowTask complete(\Atannex\Workflow\Models\WorkflowTask $task, \Illuminate\Contracts\Auth\Authenticatable $user, ?string $comment = null)
 * @method static \Atannex\Workflow\Models\WorkflowTask reassign(\Atannex\Workflow\Models\WorkflowTask $task, string|\Illuminate\Contracts\Auth\Authenticatable $assignee, mixed $by = null)
 * @method static void reconcile(\Illuminate\Database\Eloquent\Model $subject, ?\Illuminate\Contracts\Auth\Authenticatable $user = null)
 * @method static void cancel(\Atannex\Workflow\Models\WorkflowInstance $instance, mixed $actor = null, ?string $reason = null)
 * @method static \Illuminate\Support\Collection history(\Illuminate\Database\Eloquent\Model $subject, ?string $workflow = null)
 *
 * @see WorkflowEngine
 */
final class Workflow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WorkflowEngine::class;
    }
}
