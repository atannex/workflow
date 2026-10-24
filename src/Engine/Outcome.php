<?php

declare(strict_types=1);

namespace Atannex\Workflow\Engine;

use Atannex\Workflow\Models\WorkflowInstance;

/**
 * What apply(), approve() and reject() did.
 */
final class Outcome
{
    /** The state changed. */
    public const APPLIED = 'applied';

    /** A vote was counted; more approvals are needed. */
    public const PENDING = 'pending';

    /** Enough rejections: the reject transition was taken. */
    public const REJECTED = 'rejected';

    public function __construct(
        public readonly string $status,
        public readonly WorkflowInstance $instance,
        public readonly string $transition,
        public readonly ?string $from,
        public readonly string $state,
        public readonly int $approvals = 0,
        public readonly int $required = 0,
    ) {}

    public function applied(): bool
    {
        return $this->status === self::APPLIED;
    }

    public function pending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function rejected(): bool
    {
        return $this->status === self::REJECTED;
    }

    /**
     * Approvals still needed (0 once applied).
     */
    public function remaining(): int
    {
        return $this->pending() ? max(0, $this->required - $this->approvals) : 0;
    }
}
