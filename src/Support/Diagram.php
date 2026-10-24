<?php

declare(strict_types=1);

namespace Atannex\Workflow\Support;

use Atannex\Workflow\Definition\WorkflowDefinition;

/**
 * A Mermaid state diagram of a workflow (paste into docs, GitHub, mermaid.live).
 */
final class Diagram
{
    public static function mermaid(WorkflowDefinition $definition): string
    {
        $lines = ['stateDiagram-v2', "    [*] --> {$definition->initial}"];

        foreach ($definition->states as $state) {
            $lines[] = "    {$state->name} : ".self::escape($state->label);

            if ($state->final) {
                $lines[] = "    {$state->name} --> [*]";
            }
        }

        foreach ($definition->transitions as $transition) {
            $from = in_array('*', $transition->from, true)
                ? array_keys(array_filter($definition->states, fn ($state) => ! $state->final && $state->name !== $transition->to))
                : $transition->from;

            $label = $transition->label;
            $label .= $transition->approval !== null ? " ({$transition->approval->required} approvals)" : '';
            $label .= $transition->automatic ? ' [auto]' : ($transition->system ? ' [system]' : '');

            foreach ($from as $source) {
                $lines[] = "    {$source} --> {$transition->to} : ".self::escape($label);
            }
        }

        return implode("\n", $lines)."\n";
    }

    private static function escape(string $text): string
    {
        return str_replace([':', "\n"], ['﹕', ' '], $text);
    }
}
