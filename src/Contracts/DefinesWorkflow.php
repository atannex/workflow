<?php

declare(strict_types=1);

namespace Atannex\Workflow\Contracts;

use Atannex\Workflow\Definition\WorkflowDefinition;

/**
 * A workflow written as a class (listed in config/workflow.php).
 */
interface DefinesWorkflow
{
    public function define(): WorkflowDefinition;
}
