<?php

declare(strict_types=1);

namespace Atannex\Workflow\Events;

use Atannex\Workflow\Engine\Context;

/**
 * About to change state, inside the transaction. A listener may veto the
 * change with $event->block('reason'); everything is rolled back.
 */
final class Transitioning
{
    public ?string $blockedBecause = null;

    public function __construct(public readonly Context $context) {}

    public function block(string $reason): void
    {
        $this->blockedBecause = $reason;
    }
}
