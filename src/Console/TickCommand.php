<?php

declare(strict_types=1);

namespace Atannex\Workflow\Console;

use Atannex\Workflow\Engine\WorkflowEngine;
use Illuminate\Console\Command;

final class TickCommand extends Command
{
    protected $signature = 'workflow:tick';

    protected $description = 'Fire due workflow timers, take automatic transitions and escalate overdue tasks (schedule every minute)';

    public function handle(WorkflowEngine $engine): int
    {
        $report = $engine->tick();

        $this->components->info(sprintf(
            'Timers: %d · automatic: %d · overdue tasks: %d (escalated to %d) · skipped: %d',
            $report['timers'], $report['automatic'], $report['overdue'], $report['escalated'], $report['failed'],
        ));

        return self::SUCCESS;
    }
}
