<?php

declare(strict_types=1);

namespace Atannex\Workflow\Models;

use Atannex\Workflow\Support\Connections;

/**
 * Table names and connection come from config/workflow.php (and, when
 * workflows follow their subjects, from the subject's connection).
 */
trait UsesWorkflowTables
{
    abstract protected static function tableKey(): string;

    public function getTable(): string
    {
        return (string) config('workflow.tables.'.static::tableKey(), 'workflow_'.static::tableKey());
    }

    public function getConnectionName(): ?string
    {
        // Following subjects: a row read or made on a subject's own database stays there (Support\Connections).
        if (Connections::follows() && $this->connection !== null) {
            return $this->connection;
        }

        return config('workflow.connection') ?? parent::getConnectionName();
    }

    /** An instance's tasks, history and votes live on the instance's own connection. */
    protected function newRelatedInstance($class)
    {
        $related = parent::newRelatedInstance($class);

        if (Connections::follows() && in_array(UsesWorkflowTables::class, class_uses_recursive($related), true)) {
            $related->setConnection($this->getConnectionName());
        }

        return $related;
    }

    /**
     * @return class-string
     */
    protected static function userModel(): string
    {
        return (string) config('workflow.user_model');
    }
}
