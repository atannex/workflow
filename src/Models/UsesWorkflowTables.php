<?php

declare(strict_types=1);

namespace Atannex\Workflow\Models;

/**
 * Table names and connection come from config/workflow.php.
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
        return config('workflow.connection') ?? parent::getConnectionName();
    }

    /**
     * @return class-string
     */
    protected static function userModel(): string
    {
        return (string) config('workflow.user_model');
    }
}
