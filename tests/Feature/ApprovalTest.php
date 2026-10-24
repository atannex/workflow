<?php

namespace Atannex\Workflow\Tests\Feature;

use Atannex\Workflow\Events\ApprovalRecorded;
use Atannex\Workflow\Exceptions\TransitionNotAllowed;
use Atannex\Workflow\Models\WorkflowApproval;
use Atannex\Workflow\Tests\Fixtures\Article;
use Atannex\Workflow\Tests\Fixtures\EditorialWorkflow;
use Atannex\Workflow\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

class ApprovalTest extends TestCase
{
    protected function setUp(): void
    {
        EditorialWorkflow::$approvals = 2;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        EditorialWorkflow::$approvals = 1;

        parent::tearDown();
    }

    private function submitted(): array
    {
        $author = $this->user('Ayuk');
        $article = Article::query()->create(['title' => 'X', 'author_id' => $author->id]);
        $article->workflow()->apply('submit', $author);

        return [$article, $author];
    }

    public function test_the_transition_waits_for_the_quorum(): void
    {
        Event::fake([ApprovalRecorded::class]);
        [$article] = $this->submitted();
        $first = $this->user('Ebot', ['editor']);
        $second = $this->user('Fon', ['editor']);

        $outcome = $article->workflow()->apply('approve', $first);
        $this->assertTrue($outcome->pending());
        $this->assertSame(1, $outcome->remaining());
        $this->assertSame('review', $article->workflow()->state());

        // The same person cannot count twice.
        $this->assertFalse($article->workflow()->can('approve', $first));
        $this->expectExceptionObject(new TransitionNotAllowed('approve', ['You have already approved this.']));

        try {
            $article->workflow()->apply('approve', $first);
        } finally {
            $outcome = $article->workflow()->apply('approve', $second);
            $this->assertTrue($outcome->applied());
            $this->assertSame('approved', $article->workflow()->state());
            Event::assertDispatchedTimes(ApprovalRecorded::class, 2);
        }
    }

    public function test_a_rejection_sends_it_back_and_votes_do_not_carry_over(): void
    {
        [$article, $author] = $this->submitted();
        $first = $this->user('Ebot', ['editor']);
        $second = $this->user('Fon', ['editor']);

        $article->workflow()->approve('approve', $first);

        try {
            $article->workflow()->reject('approve', $second);
            $this->fail('A rejection needs a reason.');
        } catch (ValidationException) {
        }

        $outcome = $article->workflow()->reject('approve', $second, ['comment' => 'Libel risk']);
        $this->assertTrue($outcome->rejected());
        $this->assertSame('changes', $article->workflow()->state());
        $this->assertSame('request_changes', $article->workflow()->history()->last()->transition);

        // Back in review: the earlier approval belongs to the previous round.
        $article->workflow()->apply('submit', $author);
        $this->assertTrue($article->workflow()->apply('approve', $first)->pending());
        $this->assertSame(3, WorkflowApproval::query()->count());
    }

    public function test_the_system_cannot_approve(): void
    {
        [$article] = $this->submitted();

        $this->assertFalse($article->workflow()->can('approve'));
    }
}
