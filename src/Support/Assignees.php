<?php

declare(strict_types=1);

namespace Atannex\Workflow\Support;

use Atannex\Workflow\Engine\Context;
use Atannex\Workflow\Exceptions\InvalidDefinition;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who a task is for. Tasks are assigned to a user, a role or a permission;
 * a user's roles and permissions are read from spatie/laravel-permission
 * when present, or from resolveIdentitiesUsing().
 */
final class Assignees
{
    public const USER = 'user';

    public const ROLE = 'role';

    public const PERMISSION = 'permission';

    /** @var (Closure(Authenticatable): array{role?: array<int, string>, permission?: array<int, string>})|null */
    private ?Closure $identityResolver = null;

    /**
     * @param  Closure(Authenticatable): array{role?: array<int, string>, permission?: array<int, string>}  $resolver
     */
    public function resolveIdentitiesUsing(Closure $resolver): void
    {
        $this->identityResolver = $resolver;
    }

    /**
     * @return array{user: array<int, string>, role: array<int, string>, permission: array<int, string>}
     */
    public function identities(Authenticatable $user): array
    {
        $roles = [];
        $permissions = [];

        if ($this->identityResolver !== null) {
            $resolved = ($this->identityResolver)($user);
            $roles = $resolved['role'] ?? [];
            $permissions = $resolved['permission'] ?? [];
        } else {
            if (method_exists($user, 'getRoleNames')) {
                $roles = $user->getRoleNames()->all();
            }

            if (method_exists($user, 'getAllPermissions')) {
                $permissions = $user->getAllPermissions()->pluck('name')->all();
            }
        }

        return [
            self::USER => [(string) $user->getAuthIdentifier()],
            self::ROLE => array_values(array_map('strval', $roles)),
            self::PERMISSION => array_values(array_map('strval', $permissions)),
        ];
    }

    /**
     * Limit a task query to tasks this user may act on.
     */
    public function scope(Builder $query, Authenticatable $user): Builder
    {
        $identities = $this->identities($user);

        return $query->where(function (Builder $query) use ($identities) {
            foreach ($identities as $type => $names) {
                if ($names !== []) {
                    $query->orWhere(fn (Builder $q) => $q->where('assignee_type', $type)->whereIn('assignee', $names));
                }
            }
        });
    }

    public function matches(string $type, string $assignee, Authenticatable $user): bool
    {
        return in_array($assignee, $this->identities($user)[$type] ?? [], true);
    }

    /**
     * Turn assignee specs into [type, assignee] pairs.
     *
     * @param  array<int, string|int|Closure|Authenticatable>  $specs
     * @return Collection<int, array{0: string, 1: string}>
     */
    public function resolve(array $specs, Context $context): Collection
    {
        return collect($specs)
            ->flatMap(fn (mixed $spec) => $this->expand($spec, $context))
            ->filter()
            ->unique(fn (array $pair) => $pair[0].'|'.$pair[1])
            ->values();
    }

    /**
     * @return array<int, array{0: string, 1: string}|null>
     */
    private function expand(mixed $spec, Context $context): array
    {
        if ($spec instanceof Closure) {
            $spec = $spec($context);
        }

        if (is_iterable($spec) && ! is_string($spec)) {
            $pairs = [];

            foreach ($spec as $item) {
                $pairs = [...$pairs, ...$this->expand($item, $context)];
            }

            return $pairs;
        }

        if ($spec instanceof Authenticatable) {
            return [[self::USER, (string) $spec->getAuthIdentifier()]];
        }

        if ($spec === null || $spec === '') {
            return [];
        }

        if (is_int($spec)) {
            return [[self::USER, (string) $spec]];
        }

        $spec = (string) $spec;

        if ($spec === 'owner') {
            $owner = $context->ownerId();

            return $owner === null ? [] : [[self::USER, (string) $owner]];
        }

        if ($spec === 'actor') {
            $actor = $context->actor->id();

            return $actor === null ? [] : [[self::USER, (string) $actor]];
        }

        [$type, $value] = array_pad(explode(':', $spec, 2), 2, null);

        if (! in_array($type, [self::USER, self::ROLE, self::PERMISSION], true) || blank($value)) {
            throw new InvalidDefinition("Unknown task assignee [{$spec}]: use user:, role:, permission:, owner or actor.");
        }

        return array_map(fn (string $name) => [$type, $name], Values::list($value));
    }
}
