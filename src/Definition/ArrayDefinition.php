<?php

declare(strict_types=1);

namespace Atannex\Workflow\Definition;

use Atannex\Workflow\Exceptions\InvalidDefinition;
use Atannex\Workflow\Support\Values;

/**
 * Builds a definition from plain data (config files, JSON):
 *
 *     'leave' => [
 *         'for' => [LeaveRequest::class],
 *         'column' => 'status',
 *         'initial' => 'requested',
 *         'states' => [
 *             'requested' => ['tasks' => ['decide' => ['assign' => ['role:manager'], 'due' => '2 days', 'escalate_to' => ['role:hr']]]],
 *             'approved' => ['final' => true],
 *             'refused' => ['final' => true],
 *         ],
 *         'transitions' => [
 *             'approve' => ['from' => 'requested', 'to' => 'approved', 'guards' => ['role:manager', 'not-owner'], 'approvals' => 2],
 *             'refuse' => ['from' => 'requested', 'to' => 'refused', 'comment' => true],
 *         ],
 *     ],
 */
final class ArrayDefinition
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function build(array $data): WorkflowDefinition
    {
        $name = $data['name'] ?? throw new InvalidDefinition('An array workflow definition needs a name.');
        $definition = WorkflowDefinition::make($name);

        foreach (['label', 'description', 'column'] as $key) {
            if (isset($data[$key])) {
                $definition->{$key}($data[$key]);
            }
        }

        $definition->for(...Values::list($data['for'] ?? []));
        $definition->autoStart((bool) ($data['auto_start'] ?? true));
        $definition->meta($data['meta'] ?? []);

        foreach ($data['states'] ?? [] as $key => $state) {
            [$stateName, $state] = is_int($key) ? [$state, []] : [$key, $state];

            $definition->state($stateName, function (State $s) use ($state) {
                foreach (['label', 'description', 'color', 'icon'] as $key) {
                    if (isset($state[$key])) {
                        $s->{$key}($state[$key]);
                    }
                }

                $s->final((bool) ($state['final'] ?? false));
                $s->meta($state['meta'] ?? []);

                foreach ($state['tasks'] ?? [] as $taskName => $task) {
                    $s->task($taskName, function (TaskSpec $t) use ($task) {
                        $t->assign(...Values::list($task['assign'] ?? []));
                        $t->escalateTo(...Values::list($task['escalate_to'] ?? []));
                        isset($task['label']) && $t->label($task['label']);
                        isset($task['due']) && $t->due($task['due']);
                        isset($task['when_overdue']) && $t->whenOverdue($task['when_overdue']);
                        $t->meta($task['meta'] ?? []);
                    });
                }

                foreach ($state['timers'] ?? [] as $timer) {
                    $s->after($timer['after'], $timer['transition']);
                }
            });
        }

        $definition->initial($data['initial'] ?? array_key_first($definition->states) ?? '');

        foreach ($data['transitions'] ?? [] as $transitionName => $transition) {
            $definition->transition($transitionName, function (Transition $t) use ($transition) {
                $t->from(...Values::list($transition['from'] ?? []));
                isset($transition['to']) && $t->to($transition['to']);

                foreach (['label', 'color', 'icon', 'description'] as $key) {
                    if (isset($transition[$key])) {
                        $t->{$key}($transition[$key]);
                    }
                }

                isset($transition['confirm']) && $t->confirm($transition['confirm']);
                $t->guard(...Values::list($transition['guards'] ?? []));
                $t->requiresComment((bool) ($transition['comment'] ?? false));
                $t->input($transition['input'] ?? []);
                $t->automatic((bool) ($transition['automatic'] ?? false));
                $t->system((bool) ($transition['system'] ?? false));
                $t->hidden((bool) ($transition['hidden'] ?? false));
                $t->meta($transition['meta'] ?? []);

                $approvals = $transition['approvals'] ?? null;

                if ($approvals !== null) {
                    $approvals = is_int($approvals) ? ['required' => $approvals] : $approvals;

                    $t->approvals((int) ($approvals['required'] ?? 1), function (ApprovalRule $rule) use ($approvals) {
                        $rule->voters(...Values::list($approvals['voters'] ?? []));
                        isset($approvals['reject_with']) && $rule->rejectWith($approvals['reject_with'], (int) ($approvals['rejections'] ?? 1));
                    });
                }
            });
        }

        return $definition;
    }
}
