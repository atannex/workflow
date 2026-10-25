# atannex/workflow

[![Tests](https://github.com/atannex/workflow/actions/workflows/tests.yml/badge.svg)](https://github.com/atannex/workflow/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012-FF2D20?logo=laravel&logoColor=white)](composer.json)
[![License: MIT](https://img.shields.io/badge/License-MIT-2ea44f.svg)](LICENSE)

A workflow engine for Laravel Eloquent models. Define states and transitions once; the engine handles who may do what, multi-person approvals, task inboxes, deadlines with escalation, timers, automatic steps and a full audit trail. Every change runs in a database transaction with the instance row locked.

- **States and transitions**: from one or more states (or `*`) to one, with labels, colours and icons for the UI.
- **Guards**: permissions, roles, owner / not-owner (separation of duties), "not the person who did the last step" (four eyes), required fields, finished tasks, or closures. A refused step returns its reasons as text.
- **Input and comments**: per-transition validation rules (e.g. a publication date) and required comments.
- **Approvals**: *n* different people must approve. A rejection takes a reject transition. Votes belong to one visit to the state, so they never carry over after a resubmission.
- **Tasks**: entering a state hands work to users, roles, permissions, the owner or the actor. Tasks can be taken, given back, handed on and completed, and they close when the state is left.
- **Deadlines**: an overdue task is escalated to other people and/or triggers a transition, exactly once.
- **Timers**: take a transition some time after entering a state, or at a moment read from the model (e.g. `published_at`). Moving that date reschedules the timer.
- **Automatic transitions**: taken as soon as their guards pass, which lets parallel tasks join (`tasks-complete`). Chains are bounded.
- **Parallel workflows**: one model can be in several workflows at once.
- **Mirror column**: the state is copied to a model attribute. Outside changes to that attribute (forms, imports, tinker) are reconciled and recorded as a sync.
- **Lifecycle**: soft-deleting a model suspends its workflow and withdraws tasks. Restoring resumes it. A force delete removes the instance.
- **Concurrency**: row locks, `expect` state checks (`StaleState`), and unique instances per subject. Events are dispatched only after commit, and a listener can veto a transition.
- **Tooling**: `workflow:list`, `workflow:show` (with a Mermaid diagram), `workflow:validate` (unreachable states, dead ends, bad references), `workflow:tick` and `workflow:backfill`.

## Install

```bash
composer require atannex/workflow
php artisan vendor:publish --tag=workflow-config
php artisan migrate
```

Schedule the tick (timers, automatic steps, deadlines):

```php
Schedule::command('workflow:tick')->everyMinute()->withoutOverlapping(5)->onOneServer();
```

## Define a workflow

```php
use Atannex\Workflow\Contracts\DefinesWorkflow;
use Atannex\Workflow\Definition\{ApprovalRule, State, TaskSpec, Transition, WorkflowDefinition};
use Atannex\Workflow\Engine\Context;

final class InvoiceWorkflow implements DefinesWorkflow
{
    public function define(): WorkflowDefinition
    {
        return WorkflowDefinition::make('invoice')
            ->for(Invoice::class)
            ->column('status')                                   // mirror the state here
            ->ownerUsing(fn (Invoice $i) => $i->created_by)      // for owner / not-owner
            ->inferUsing(fn (Invoice $i) => $i->paid_at ? 'paid' : null) // for existing rows
            ->state('draft')
            ->state('approval', fn (State $s) => $s
                ->task('approve', fn (TaskSpec $t) => $t
                    ->assign('permission:invoices.approve')
                    ->due('2 days')
                    ->escalateTo('role:finance-director')))
            ->state('approved', fn (State $s) => $s->after('30 days', 'remind'))
            ->state('paid', fn (State $s) => $s->final())
            ->state('void', fn (State $s) => $s->final())
            ->transition('submit', fn (Transition $t) => $t
                ->from('draft')->to('approval')
                ->guard('owner', 'filled:customer_id,total'))
            ->transition('approve', fn (Transition $t) => $t
                ->from('approval')->to('approved')
                ->guard('can:invoices.approve', 'not-owner')
                ->approvals(2, fn (ApprovalRule $r) => $r->rejectWith('send_back')))
            ->transition('send_back', fn (Transition $t) => $t
                ->from('approval')->to('draft')->requiresComment())
            ->transition('pay', fn (Transition $t) => $t
                ->from('approved')->to('paid')
                ->input(['paid_at' => ['required', 'date']])
                ->before(fn (Context $c) => $c->subject->paid_at = $c->input('paid_at')))
            ->transition('remind', fn (Transition $t) => $t->from('approved')->to('approved')->system())
            ->transition('void', fn (Transition $t) => $t->from('*')->to('void')->guard('can:invoices.void'));
    }
}
```

Register it in `config/workflow.php` (`'workflows' => [InvoiceWorkflow::class]`), or call `app(WorkflowRegistry::class)->register(...)`. Array definitions work too (see `Definition\ArrayDefinition`).

Add the trait to the model:

```php
use Atannex\Workflow\Concerns\HasWorkflow;

class Invoice extends Model
{
    use HasWorkflow;
}
```

## Use it

```php
$invoice->workflow()->state();                      // "draft"
$invoice->workflow()->available($user);             // transitions $user may take now
$invoice->workflow()->check('submit', $user);       // GuardResult: ->allowed, ->reasons
$invoice->workflow()->apply('submit', $user, ['comment' => 'Q3 services']);

$outcome = $invoice->workflow()->apply('approve', $alice);   // vote 1 of 2
$outcome->pending(); $outcome->remaining();                  // true, 1
$invoice->workflow()->reject('approve', $bob, ['comment' => 'Wrong VAT rate']); // → send_back

$invoice->workflow()->apply('pay', $user, [
    'input' => ['paid_at' => now()],
    'expect' => 'approved',            // throws StaleState if someone moved it meanwhile
]);

$invoice->workflow()->history();       // WorkflowHistory rows: who, when, from → to, comment, input
Invoice::inWorkflowState('approval')->get();
```

Tasks:

```php
use Atannex\Workflow\Facades\Workflow;
use Atannex\Workflow\Models\WorkflowTask;

WorkflowTask::query()->for($user)->get();   // the user's inbox (direct, role, permission, claimed)
WorkflowTask::query()->overdue()->get();
Workflow::claim($task, $user);  Workflow::release($task, $user);
Workflow::complete($task, $user, 'Checked');
Workflow::reassign($task, 'role:legal', $by);
```

Who holds which roles and permissions is read from spatie/laravel-permission when installed. Otherwise use `app(Assignees::class)->resolveIdentitiesUsing(fn ($user) => ['role' => [...], 'permission' => [...]])`.

Votes on an approval transition, for this visit to the state:

```php
$instance = $invoice->workflow()->instance();
$instance->approvalCount('approve');          // approvals so far
$instance->approvedBy('approve', $user->id);   // has this user approved?
$instance->votes('approve')->get()->filter->isApproval();
```

## Guards

| Guard                             | Passes when                                            |
| --------------------------------- | ------------------------------------------------------ |
| `can:ability`                     | `Gate::forUser($actor)->allows('ability', $subject)`   |
| `role:a,b`                        | the actor holds one of the roles                       |
| `owner` / `not-owner`             | the actor is / is not the owner (`ownerUsing`)         |
| `not-actor:submit,claim`          | the actor did not take the latest of these transitions |
| `filled:title,body`               | the attributes are filled in                           |
| `tasks-complete[:a,b]`            | every (named) task of the current visit is done        |
| `user`                            | a signed-in user is acting (not the system)            |
| `new AnyOf(...)`                  | any one of the guards passes                           |
| `fn (Context $c) => bool\|string` | your rule (a string is the refusal reason)             |

The system actor (timers, deadlines, automatic steps) passes permission, role and owner guards but is still subject to `filled`, `tasks-complete` and closures. Transitions marked `->system()` can only be taken by the system, and approvals only by people. Add your own aliases with `app(GuardFactory::class)->extend('name', fn (array $args) => new MyGuard(...$args))`.

## Events

| Event                                                                                       | When                                                                           |
| ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| `Transitioning`                                                                             | inside the transaction, before the change; `$event->block('reason')` vetoes it |
| `Transitioned`, `StateEntered`, `WorkflowStarted`, `WorkflowCompleted`, `WorkflowCancelled` | after commit                                                                   |
| `ApprovalRecorded`                                                                          | a vote was cast                                                                |
| `TaskAssigned` (`$escalated`), `TaskCompleted`, `TaskOverdue`                               | task lifecycle                                                                 |

## Commands

```bash
php artisan workflow:list                     # workflows, models, instances per state
php artisan workflow:show invoice [--mermaid] # states, tasks, timers, transitions
php artisan workflow:validate                 # errors and warnings for every definition
php artisan workflow:backfill [invoice] [--dry-run]   # start rows that predate the workflow
php artisan workflow:tick                     # timers, automatic steps, deadlines
```

## Tests

```bash
cd packages/atannex/workflow && composer install && vendor/bin/phpunit
```
