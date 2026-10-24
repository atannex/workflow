<?php

declare(strict_types=1);

namespace Atannex\Workflow;

use Atannex\Workflow\Contracts\DefinesWorkflow;
use Atannex\Workflow\Definition\ArrayDefinition;
use Atannex\Workflow\Definition\WorkflowDefinition;
use Atannex\Workflow\Exceptions\InvalidDefinition;
use Atannex\Workflow\Exceptions\UnknownWorkflow;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * Every workflow the application knows: from config/workflow.php (classes
 * or arrays) and from register() calls. Definitions are built lazily, once.
 */
final class WorkflowRegistry
{
    /** @var array<string, WorkflowDefinition> */
    private array $definitions = [];

    /** @var array<int, string|array<string, mixed>|Closure|WorkflowDefinition> */
    private array $pending = [];

    private bool $loaded = false;

    /**
     * @param  array<int|string, mixed>  $configured
     */
    public function __construct(private readonly Container $container, array $configured = [])
    {
        foreach ($configured as $key => $definition) {
            $this->pending[] = is_array($definition) && is_string($key) ? ['name' => $key, ...$definition] : $definition;
        }
    }

    /**
     * A definition object, a DefinesWorkflow class, an array or a closure
     * returning a definition.
     *
     * @param  WorkflowDefinition|class-string<DefinesWorkflow>|array<string, mixed>|Closure(): WorkflowDefinition  $definition
     */
    public function register(WorkflowDefinition|string|array|Closure $definition): void
    {
        if ($definition instanceof WorkflowDefinition) {
            $this->definitions[$definition->name] = $definition;

            return;
        }

        $this->pending[] = $definition;
        $this->loaded = false;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function get(string $name): WorkflowDefinition
    {
        return $this->all()[$name] ?? throw UnknownWorkflow::make($name);
    }

    /**
     * @return array<string, WorkflowDefinition>
     */
    public function all(): array
    {
        if (! $this->loaded) {
            while ($this->pending !== []) {
                $definition = $this->build(array_shift($this->pending));
                $this->definitions[$definition->name] = $definition;
            }

            $this->loaded = true;
        }

        return $this->definitions;
    }

    /**
     * Workflows that run on a model.
     *
     * @return array<string, WorkflowDefinition>
     */
    public function forSubject(Model|string $subject): array
    {
        return array_filter($this->all(), fn (WorkflowDefinition $definition) => $definition->appliesTo($subject));
    }

    /**
     * The workflow to use for a subject: the named one, or its only one.
     */
    public function resolve(Model|string $subject, ?string $name = null): WorkflowDefinition
    {
        if ($name !== null) {
            return $this->get($name);
        }

        $class = $subject instanceof Model ? $subject::class : $subject;
        $definitions = $this->forSubject($subject);

        return match (count($definitions)) {
            0 => throw UnknownWorkflow::forSubject($class),
            1 => reset($definitions),
            default => throw UnknownWorkflow::ambiguous($class),
        };
    }

    private function build(mixed $definition): WorkflowDefinition
    {
        $built = match (true) {
            $definition instanceof WorkflowDefinition => $definition,
            $definition instanceof Closure => $definition(),
            is_array($definition) => ArrayDefinition::build($definition),
            is_string($definition) && is_subclass_of($definition, DefinesWorkflow::class) => $this->container->make($definition)->define(),
            default => null,
        };

        if (! $built instanceof WorkflowDefinition) {
            throw new InvalidDefinition('Cannot read a workflow definition from ['.(is_string($definition) ? $definition : get_debug_type($definition)).'].');
        }

        return $built->assertValid();
    }
}
