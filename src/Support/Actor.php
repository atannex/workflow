<?php

declare(strict_types=1);

namespace Atannex\Workflow\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who performs a change: a user, the system (timers, automatic transitions,
 * the scheduler) or an outside change picked up by reconciliation.
 */
final class Actor
{
    public const USER = 'user';

    public const SYSTEM = 'system';

    public const SYNC = 'sync';

    private function __construct(
        public readonly string $kind,
        public readonly ?Authenticatable $user = null,
    ) {}

    public static function user(Authenticatable $user): self
    {
        return new self(self::USER, $user);
    }

    public static function system(): self
    {
        return new self(self::SYSTEM);
    }

    public static function sync(?Authenticatable $user = null): self
    {
        return new self(self::SYNC, $user);
    }

    /**
     * A user, an Actor, or null for the system.
     */
    public static function from(Authenticatable|self|null $actor): self
    {
        return match (true) {
            $actor instanceof self => $actor,
            $actor instanceof Authenticatable => self::user($actor),
            default => self::system(),
        };
    }

    public function isSystem(): bool
    {
        return $this->kind !== self::USER;
    }

    public function id(): int|string|null
    {
        return $this->user?->getAuthIdentifier();
    }

    public function is(Authenticatable|int|string|null $user): bool
    {
        $id = $user instanceof Authenticatable ? $user->getAuthIdentifier() : $user;

        return $id !== null && $this->id() !== null && (string) $id === (string) $this->id();
    }
}
