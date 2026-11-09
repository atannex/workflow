<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Workflow definitions
    |--------------------------------------------------------------------------
    |
    | Classes implementing Atannex\Workflow\Contracts\DefinesWorkflow, or
    | array definitions keyed by workflow name (see README "Array definitions").
    |
    */

    'workflows' => [
        // App\Workflows\EditorialWorkflow::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    |
    | The model that acts on workflows (history, votes, tasks), and the
    | abilities used to match tasks assigned to a role or a permission.
    |
    */

    'user_model' => env('WORKFLOW_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    */

    'connection' => env('WORKFLOW_DB_CONNECTION'),

    /*
    | An application that keeps some models on their own database (a model
    | with its own $connection) can keep their workflows there too, so
    | inWorkflowState() still runs in one query. Each such database needs the
    | workflow tables. The scheduler and listings run on "connection" and on
    | each of "connections".
    */
    'follow_subject_connection' => (bool) env('WORKFLOW_FOLLOW_SUBJECT_CONNECTION', false),

    'connections' => [],

    'tables' => [
        'instances' => 'workflow_instances',
        'history' => 'workflow_history',
        'approvals' => 'workflow_approvals',
        'tasks' => 'workflow_tasks',
    ],

    /*
    |--------------------------------------------------------------------------
    | Engine
    |--------------------------------------------------------------------------
    |
    | max_automatic_hops: how many automatic transitions may chain after one
    | change before the engine stops (protects against definition loops).
    |
    | tick_batch: how many due timers, automatic checks and overdue tasks one
    | `workflow:tick` run handles per kind.
    |
    */

    'max_automatic_hops' => 10,

    'tick_batch' => 500,

    /*
    | Keep a subject's mirror column and its workflow state in step when the
    | subject is changed outside the engine (forms, imports, tinker): the
    | change is recorded in the history as a "sync" by whoever made it.
    */

    'reconcile_external_changes' => true,

];
