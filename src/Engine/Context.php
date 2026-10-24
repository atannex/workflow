<?php

declare(strict_types=1);

namespace Atannex\Workflow\Engine;

use Atannex\Workflow\Definition\Transition;
use Atannex\Workflow\Definition\WorkflowDefinition;
use Atannex\Workflow\Models\WorkflowInstance;
use Atannex\Workflow\Support\Actor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Everything a guard, effect, hook or assignee resolver may need about the
 * change in progress.
 */
final class Context
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly WorkflowDefinition $definition,
        public readonly WorkflowInstance $instance,
        public readonly Model $subject,
        public readonly Actor $actor,
        public readonly ?Transition $transition = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?string $comment = null,
        public readonly array $input = [],
    ) {}

    public function user(): ?Authenticatable
    {
        return $this->actor->user;
    }

    public function isSystem(): bool
    {
        return $this->actor->isSystem();
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->input, $key, $default);
    }

    /**
     * The owner's id, from the definition's ownerUsing().
     */
    public function ownerId(): int|string|null
    {
        $owner = $this->definition->owner($this->subject);

        return $owner instanceof Authenticatable ? $owner->getAuthIdentifier() : $owner;
    }

    public function actorIsOwner(): bool
    {
        return $this->actor->is($this->ownerId());
    }

    /**
     * Data kept on the instance across transitions.
     */
    public function data(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->instance->context ?? [], $key, $default);
    }

    public function remember(string $key, mixed $value): void
    {
        $context = $this->instance->context ?? [];
        Arr::set($context, $key, $value);
        $this->instance->context = $context;
    }

    public function forget(string $key): void
    {
        $context = $this->instance->context ?? [];
        Arr::forget($context, $key);
        $this->instance->context = $context;
    }

    public function withTransition(Transition $transition, ?string $comment = null, array $input = []): self
    {
        return new self($this->definition, $this->instance, $this->subject, $this->actor, $transition, $this->instance->state, $transition->to, $comment, $input);
    }
}
