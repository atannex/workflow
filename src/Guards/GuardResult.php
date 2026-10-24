<?php

declare(strict_types=1);

namespace Atannex\Workflow\Guards;

final class GuardResult
{
    /**
     * @param  array<int, string>  $reasons
     */
    private function __construct(public readonly bool $allowed, public readonly array $reasons = []) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string ...$reasons): self
    {
        return new self(false, array_values($reasons));
    }

    public static function from(mixed $value, string $fallback = 'Not allowed.'): self
    {
        return match (true) {
            $value instanceof self => $value,
            $value === true || $value === null => self::allow(),
            is_string($value) => self::deny($value),
            default => self::deny($fallback),
        };
    }

    public function denied(): bool
    {
        return ! $this->allowed;
    }

    public function merge(self $other): self
    {
        return $this->allowed && $other->allowed
            ? self::allow()
            : new self(false, array_values(array_unique([...$this->reasons, ...$other->reasons])));
    }

    public function message(): string
    {
        return implode(' ', $this->reasons);
    }
}
