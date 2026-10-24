<?php

declare(strict_types=1);

namespace Atannex\Workflow\Console;

use Atannex\Workflow\Exceptions\InvalidDefinition;
use Atannex\Workflow\WorkflowRegistry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;

final class ValidateCommand extends Command
{
    protected $signature = 'workflow:validate';

    protected $description = 'Check every workflow definition for errors and suspicious spots (unreachable states, dead ends…)';

    public function handle(Container $container): int
    {
        try {
            $definitions = $container->make(WorkflowRegistry::class)->all();
        } catch (InvalidDefinition $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $failed = false;

        foreach ($definitions as $definition) {
            ['errors' => $errors, 'warnings' => $warnings] = $definition->problems();

            if ($errors === [] && $warnings === []) {
                $this->components->twoColumnDetail($definition->name, '<fg=green>OK</>');

                continue;
            }

            $this->components->twoColumnDetail($definition->name, $errors !== [] ? '<fg=red>ERRORS</>' : '<fg=yellow>WARNINGS</>');
            $this->components->bulletList([...array_map(fn ($e) => "<fg=red>{$e}</>", $errors), ...$warnings]);
            $failed = $failed || $errors !== [];
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
