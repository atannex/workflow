<?php

namespace Atannex\Workflow\Tests\Fixtures;

use Atannex\Workflow\Contracts\DefinesWorkflow;
use Atannex\Workflow\Definition\ApprovalRule;
use Atannex\Workflow\Definition\State;
use Atannex\Workflow\Definition\TaskSpec;
use Atannex\Workflow\Definition\Transition;
use Atannex\Workflow\Definition\WorkflowDefinition;
use Atannex\Workflow\Engine\Context;

class EditorialWorkflow implements DefinesWorkflow
{
    public static int $approvals = 1;

    public function define(): WorkflowDefinition
    {
        return WorkflowDefinition::make('editorial')
            ->for(Article::class)
            ->column('status')
            ->ownerUsing(fn (Article $article) => $article->author_id)
            ->inferUsing(fn (Article $article) => $article->published_at?->isPast() ? 'published' : null)
            ->initial('draft')
            ->state('draft')
            ->state('review', fn (State $s) => $s
                ->task('review', fn (TaskSpec $t) => $t->assign('role:editor')->due('1 day')->escalateTo('role:chief')))
            ->state('changes', fn (State $s) => $s->assign('owner'))
            ->state('approved')
            ->state('scheduled', fn (State $s) => $s->at(fn (Context $c) => $c->subject->published_at, 'go_live'))
            ->state('published', fn (State $s) => $s->final())
            ->transition('submit', fn (Transition $t) => $t->from('draft', 'changes')->to('review')->guard('owner', 'filled:title'))
            ->transition('request_changes', fn (Transition $t) => $t->from('review')->to('changes')->guard('role:editor')->requiresComment())
            ->transition('approve', fn (Transition $t) => $t->from('review')->to('approved')
                ->guard('role:editor', 'not-owner')
                ->approvals(self::$approvals, fn (ApprovalRule $r) => $r->rejectWith('request_changes')))
            ->transition('publish', fn (Transition $t) => $t->from('approved')->to('published')->guard('role:editor')
                ->before(fn (Context $c) => $c->subject->published_at = now()))
            ->transition('schedule', fn (Transition $t) => $t->from('approved')->to('scheduled')->guard('role:editor')
                ->input(['publish_at' => ['required', 'date', 'after:now']])
                ->before(fn (Context $c) => $c->subject->published_at = $c->input('publish_at')))
            ->transition('go_live', fn (Transition $t) => $t->from('scheduled')->to('published')->system())
            ->transition('retract', fn (Transition $t) => $t->from('published')->to('draft')->guard('role:editor'));
    }
}
