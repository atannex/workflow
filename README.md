# Workflow

Atannex Workflow helps you manage processes that move through defined steps. For example, a document can move from **draft** to **review**, then to **approved** or **rejected**. You define the states, transitions and rules; the package manages the process and keeps a record of its activity.

It includes permissions and guards, multi-person approvals, task assignment, deadlines, timers, automatic transitions and an audit trail.

## Requirements

- PHP 8.3 or later
- Laravel 11, 12 or 13
- Optional: [spatie/laravel-permission](https://github.com/spatie/laravel-permission) for role and permission checks

## Installation

Install through Composer:

```bash
composer require atannex/workflow
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=workflow-config
```

Run the migrations:

```bash
php artisan migrate
```

The package migrations are included. Publish them with `--tag=workflow-migrations` only if you need to customise the tables.

## Quick start

### 1. Add the trait to your model

The example below uses a `status` column to mirror the workflow state and an `author_id` column to identify the post's owner, so make sure your `posts` table has both:

```php
$table->string('status')->nullable();
$table->foreignId('author_id')->constrained('users');
```

Then add the trait:

```php
use Atannex\Workflow\Concerns\HasWorkflow;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasWorkflow;
}
```

### 2. Define a workflow

Create `app/Workflows/EditorialWorkflow.php`:

```php
namespace App\Workflows;

use App\Models\Post;
use Atannex\Workflow\Contracts\DefinesWorkflow;
use Atannex\Workflow\Definition\State;
use Atannex\Workflow\Definition\Transition;
use Atannex\Workflow\Definition\WorkflowDefinition;

class EditorialWorkflow implements DefinesWorkflow
{
    public function define(): WorkflowDefinition
    {
        return WorkflowDefinition::make('editorial')
            ->for(Post::class)
            ->column('status')
            ->ownerUsing(fn (Post $post) => $post->author_id)
            ->state('draft')
            ->state('review', fn (State $state) => $state->assign('role:editor'))
            ->state('published', fn (State $state) => $state->final())
            ->transition('submit', fn (Transition $transition) => $transition
                ->from('draft')->to('review')
                ->guard('owner', 'filled:title,body'))
            ->transition('publish', fn (Transition $transition) => $transition
                ->from('review')->to('published')
                ->guard('role:editor'));
    }
}
```

### 3. Register the workflow

Add the class to the `workflows` array in `config/workflow.php`:

```php
'workflows' => [
    App\Workflows\EditorialWorkflow::class,
],
```

### 4. Use it

```php
$post = Post::create([
    'title' => 'My first post',
    'body' => 'Post content',
    'author_id' => $author->id,
]);
// The workflow starts automatically: the post is now in "draft".

// Apply a transition.
$post->workflow()->apply('submit', $author);

// Check the current state and available actions.
$post->workflow()->state();
$post->workflow()->available($editor);

// Move the post to its final state.
$post->workflow()->apply('publish', $editor);

$post->workflow()->is('published'); // true
$post->workflow()->history();
```

`$editor` must hold the `editor` role. Roles are read from spatie/laravel-permission when it is installed. Without it, tell the package how to find a user's roles, for example in `AppServiceProvider::boot()`:

```php
use Atannex\Workflow\Support\Assignees;

app(Assignees::class)->resolveIdentitiesUsing(fn ($user) => [
    'role' => $user->roles->pluck('name')->all(),
    'permission' => [],
]);
```

## What it supports

- **States and transitions:** Define the stages of a process and how records move between them.
- **Guards:** Restrict transitions using ownership, roles, permissions, abilities or custom rules.
- **Approvals:** Require multiple people to approve or reject a transition.
- **Tasks:** Assign work to users, roles or permissions and manage task inboxes.
- **Deadlines and escalation:** Set due dates and define what happens when work is late.
- **Timers and automation:** Trigger transitions after a delay or when conditions are met.
- **Audit history:** Track workflow changes, actors, comments and timestamps.
- **Laravel integration:** Work with Eloquent models, events, soft deletes and Artisan commands.

## Scheduling

If you use timers, deadlines or automatic transitions, register the scheduler command in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('workflow:tick')
    ->everyMinute()
    ->withoutOverlapping();
```

Make sure Laravel's scheduler is running on your server.
