<?php

declare(strict_types=1);

namespace Atannex\Workflow\Guards;

use Atannex\Workflow\Contracts\Guard;
use Atannex\Workflow\Engine\Context;
use Atannex\Workflow\Exceptions\InvalidDefinition;
use Atannex\Workflow\Support\Values;
use Closure;

/**
 * Turns the guard shorthand used in definitions into Guard objects, and
 * checks lists of them. Applications add their own aliases with extend().
 */
final class GuardFactory
{
    /** @var array<string, Closure(array<int, string>): Guard> */
    private array $aliases = [];

    public function __construct()
    {
        $this->extend('can', fn (array $args) => new Can($args[0] ?? throw new InvalidDefinition('"can:" needs an ability.')));
        $this->extend('role', fn (array $args) => new HasRole($args));
        $this->extend('owner', fn () => new Owner(true));
        $this->extend('not-owner', fn () => new Owner(false));
        $this->extend('not-actor', fn (array $args) => new NotPreviousActor($args));
        $this->extend('filled', fn (array $args) => new Filled($args));
        $this->extend('tasks-complete', fn (array $args) => new TasksComplete($args));
        $this->extend('user', fn () => new Callback(fn (Context $c) => $c->user() !== null ?: __('Only a signed-in user can do this.')));
    }

    /**
     * @param  Closure(array<int, string>): Guard  $factory
     */
    public function extend(string $alias, Closure $factory): void
    {
        $this->aliases[$alias] = $factory;
    }

    public function make(string|Guard|Closure $guard): Guard
    {
        if ($guard instanceof Guard) {
            return $guard;
        }

        if ($guard instanceof Closure) {
            return new Callback($guard);
        }

        [$alias, $arguments] = array_pad(explode(':', $guard, 2), 2, null);

        if (! isset($this->aliases[$alias])) {
            if (class_exists($guard) && is_subclass_of($guard, Guard::class)) {
                return app($guard);
            }

            throw new InvalidDefinition("Unknown workflow guard [{$guard}].");
        }

        return ($this->aliases[$alias])(Values::list($arguments));
    }

    /**
     * Every guard must pass; all reasons are collected.
     *
     * @param  array<int, string|Guard|Closure>  $guards
     */
    public function check(array $guards, Context $context): GuardResult
    {
        $result = GuardResult::allow();

        foreach ($guards as $guard) {
            $result = $result->merge($this->make($guard)->check($context));
        }

        return $result;
    }
}
