<?php

declare(strict_types=1);

namespace Atannex\Workflow\Support;

use BackedEnum;

final class Values
{
    /**
     * A state stored as a string, a backed enum or a value object
     * (e.g. BenSampo enums) as a plain string.
     */
    public static function string(mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' => null,
            $value instanceof BackedEnum => (string) $value->value,
            is_object($value) && property_exists($value, 'value') => (string) $value->value,
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    /**
     * "a,b" or ["a", "b"] as a list.
     *
     * @return array<int, string>
     */
    public static function list(string|array|null $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = is_array($value) ? $value : explode(',', $value);

        return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $items), fn ($item) => $item !== ''));
    }
}
