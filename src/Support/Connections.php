<?php

declare(strict_types=1);

namespace Atannex\Workflow\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Which database holds a subject's workflow.
 *
 * By default every instance, task, history row and vote lives on
 * config('workflow.connection'). With workflow.follow_subject_connection on,
 * a subject whose model names its own connection keeps its workflow there
 * too, so scopes like inWorkflowState() still run in one query. Work that
 * spans every subject (the scheduler, listings) runs on each of all().
 */
final class Connections
{
    public static function follows(): bool
    {
        return (bool) config('workflow.follow_subject_connection', false);
    }

    /** The connection holding the subject's workflow. */
    public static function for(Model $subject): ?string
    {
        $default = config('workflow.connection');

        return self::follows() && ! self::central($subject) ? ($subject->getConnectionName() ?? $default) : $default;
    }

    /**
     * Whether the subject's workflow stays on the default connection even
     * while workflows follow their subjects (workflow.central_subjects):
     * a review queue that spans subjects on several databases, say.
     */
    public static function central(Model $subject): bool
    {
        foreach ((array) config('workflow.central_subjects', []) as $class) {
            if ($subject instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every connection that may hold workflows: the default one and
     * config('workflow.connections').
     *
     * @return list<string|null>
     */
    public static function all(): array
    {
        $all = [config('workflow.connection')];
        $seen = [self::target($all[0]) => true];

        if (self::follows()) {
            foreach ((array) config('workflow.connections', []) as $connection) {
                // Two names for one database (a connection not split off yet) would count its rows twice.
                if (! isset($seen[$target = self::target($connection)])) {
                    $seen[$target] = true;
                    $all[] = $connection;
                }
            }
        }

        return $all;
    }

    /** Where a connection's data lives; every in-memory SQLite connection is a database of its own. */
    public static function target(?string $connection): string
    {
        $name = $connection ?? (string) config('database.default');
        $config = (array) config("database.connections.{$name}", []);

        if (($config['driver'] ?? null) === 'sqlite' && ($config['database'] ?? null) === ':memory:') {
            return 'memory:'.$name;
        }

        return implode('|', [$config['driver'] ?? '', $config['url'] ?? '', $config['host'] ?? '', $config['port'] ?? '', $config['database'] ?? '']);
    }
}
